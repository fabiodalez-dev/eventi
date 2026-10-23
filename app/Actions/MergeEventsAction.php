<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Support\ContentVersion;
use App\Support\Redirect\RegistroRedirect;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Due schede per lo stesso evento diventano una.
 *
 * Succede: un import e un gestore scrivono la stessa serata, un organizzatore
 * la propone e il locale la pubblica. Cancellare il doppione a mano perde
 * tutto quello che ci si era appoggiato — salvataggi, prenotazioni, commenti,
 * link condivisi, il traffico misurato — e lascia un indirizzo morto che
 * qualcuno ha già mandato in giro.
 *
 * Qui invece: le date del doppione che coincidono con una data della scheda
 * che resta (stesso istante) si fondono su quella, e ogni cosa che le
 * puntava passa alla data che resta; le date che non hanno una gemella si
 * spostano con un numero nuovo. Tag, follow, commenti, sondaggi, link,
 * segnalazioni, sponsorizzazioni e statistiche passano alla scheda che resta.
 * I campi vuoti della scheda che resta si riempiono con quelli del doppione,
 * la locandina compresa se manca. L'indirizzo del doppione diventa un redirect.
 * Il doppione viene cancellato (soft), così la storia resta leggibile.
 */
final class MergeEventsAction
{
    public function __construct(private readonly RegistroRedirect $redirects) {}

    /**
     * @return array{dates_merged: int, dates_moved: int, saves: int, bookings: int}
     */
    public function execute(Event $keep, Event $absorbed, User $actor): array
    {
        if ($keep->is($absorbed)) {
            return ['dates_merged' => 0, 'dates_moved' => 0, 'saves' => 0, 'bookings' => 0];
        }

        return DB::transaction(function () use ($keep, $absorbed, $actor): array {
            $keep = Event::query()->lockForUpdate()->findOrFail($keep->getKey());
            $absorbed = Event::query()->lockForUpdate()->findOrFail($absorbed->getKey());
            $report = ['dates_merged' => 0, 'dates_moved' => 0, 'saves' => 0, 'bookings' => 0];

            foreach ($absorbed->occurrences()->orderBy('starts_at')->get() as $date) {
                $twin = $keep->occurrences()->where('starts_at', $date->starts_at)->orderBy('id')->first();
                if ($twin instanceof EventOccurrence) {
                    $this->mergeDate($date, $twin, $report);
                    $report['dates_merged']++;
                } else {
                    $this->moveDate($date, $keep);
                    $report['dates_moved']++;
                }
            }
            DB::table('event_recurrences')->where('event_id', $absorbed->getKey())->update(['event_id' => $keep->getKey()]);

            $this->mergeEventRelations($keep, $absorbed);
            $this->fillGaps($keep, $absorbed);
            $this->moveMedia($keep, $absorbed);

            $keep->saves_count = DB::table('saved_events')->whereIn('occurrence_id', $keep->occurrences()->select('id'))->count();
            $keep->views_count = (int) $keep->views_count + (int) $absorbed->views_count;
            $keep->save();

            $this->redirects->registra((int) $absorbed->city_id, '/eventi/'.$absorbed->slug, '/eventi/'.$keep->slug);
            $this->redirects->registra((int) $absorbed->city_id, '/eventi/'.$absorbed->slug.'/*', '/eventi/'.$keep->slug, true);

            activity('event')->causedBy($actor)->performedOn($keep)->event('merged')
                ->withProperties(['absorbed_id' => $absorbed->getKey(), 'absorbed_title' => $absorbed->title, ...$report])
                ->log('merged');
            activity('event')->causedBy($actor)->performedOn($absorbed)->event('merged_into')
                ->withProperties(['kept_id' => $keep->getKey(), 'kept_title' => $keep->title])
                ->log('merged_into');

            $absorbed->delete();
            DB::afterCommit(fn () => ContentVersion::bump((int) $keep->city_id));

            return $report;
        });
    }

    /**
     * Una data che ha una gemella nella scheda che resta: tutto ciò che la
     * puntava passa alla gemella, con i vincoli unici rispettati (chi aveva
     * salvato entrambe ne tiene una), poi la data sparisce.
     *
     * @param  array{dates_merged: int, dates_moved: int, saves: int, bookings: int}  $report
     */
    private function mergeDate(EventOccurrence $from, EventOccurrence $to, array &$report): void
    {
        $report['saves'] += $this->repointUnique('saved_events', 'occurrence_id', $from, $to, ['user_id']);
        $this->repointUnique('community_attendances', 'occurrence_id', $from, $to, ['user_id']);
        $this->repointUnique('community_restrictions', 'occurrence_id', $from, $to, ['user_id']);
        $this->repointUnique('event_poll_options', 'occurrence_id', $from, $to, ['event_poll_id']);
        $this->repointUnique('occurrence_checkin_staff', 'occurrence_id', $from, $to, ['user_id']);
        $this->repointUnique('ride_occupancies', 'occurrence_id', $from, $to, ['user_id', 'leg']);
        $this->repointUnique('event_collection_items', 'occurrence_id', $from, $to, ['collection_id']);
        $this->repointUnique('availability_alerts', 'occurrence_id', $from, $to, ['user_id']);
        $this->sumDaily('occurrence_views_daily', 'occurrence_id', $from, $to);

        $report['bookings'] += DB::table('bookings')->where('occurrence_id', $from->getKey())->update(['occurrence_id' => $to->getKey()]);
        foreach (['presence_shares', 'booking_messages', 'community_posts', 'ride_offers', 'ride_searches', 'ticket_tiers', 'event_share_links'] as $table) {
            DB::table($table)->where('occurrence_id', $from->getKey())->update(['occurrence_id' => $to->getKey()]);
        }
        if (! DB::table('lineups')->where('occurrence_id', $to->getKey())->exists()) {
            DB::table('lineups')->where('occurrence_id', $from->getKey())->update(['occurrence_id' => $to->getKey()]);
        }
        DB::table('scheduled_notifications')->where('notifiable_type', $from->getMorphClass())->where('notifiable_id', $from->getKey())
            ->where('status', 'pending')->update(['notifiable_id' => $to->getKey()]);

        $from->delete();
    }

    /** Una data senza gemella passa alla scheda che resta, con un numero nuovo nella sua serie. */
    private function moveDate(EventOccurrence $date, Event $keep): void
    {
        $number = (int) DB::table('events')->where('id', $keep->getKey())->value('next_occurrence_number');
        DB::table('events')->where('id', $keep->getKey())->update(['next_occurrence_number' => $number + 1]);
        DB::table('event_occurrences')->where('id', $date->getKey())->update(['event_id' => $keep->getKey(), 'url_number' => $number]);
    }

    private function mergeEventRelations(Event $keep, Event $absorbed): void
    {
        $this->repointUnique('event_tag', 'event_id', $absorbed, $keep, ['tag_id']);
        $this->repointMorph('follows', 'followable', $absorbed, $keep, ['user_id']);
        $this->repointMorph('reports', 'reportable', $absorbed, $keep, []);
        $this->sumDaily('event_views_daily', 'event_id', $absorbed, $keep);

        foreach (['event_comments', 'event_polls', 'event_share_links', 'event_submissions', 'promotions', 'sponsorships', 'ticket_tiers'] as $table) {
            DB::table($table)->where('event_id', $absorbed->getKey())->update(['event_id' => $keep->getKey()]);
        }
        DB::table('scheduled_notifications')->where('notifiable_type', $absorbed->getMorphClass())->where('notifiable_id', $absorbed->getKey())
            ->where('status', 'pending')->update(['notifiable_id' => $keep->getKey()]);
    }

    /**
     * I campi che la scheda che resta non ha e il doppione sì. Non si
     * sovrascrive mai un valore presente: chi ha scelto quale scheda tenere
     * ha scelto anche i suoi testi.
     */
    private function fillGaps(Event $keep, Event $absorbed): void
    {
        foreach (['subtitle', 'description', 'short_description', 'price_type', 'price_min', 'price_max', 'price_notes', 'ticket_url',
            'booking_url', 'booking_phone', 'age_restriction', 'organizer_id', 'organizer_name', 'organizer_url', 'external_links', 'facts', 'content_details', 'poster'] as $field) {
            $current = $keep->getAttribute($field);
            $other = $absorbed->getAttribute($field);
            if (($current === null || $current === '' || $current === []) && $other !== null && $other !== '' && $other !== []) {
                $keep->setAttribute($field, $other);
            }
        }
    }

    private function moveMedia(Event $keep, Event $absorbed): void
    {
        if ($keep->getFirstMedia('poster') === null) {
            $absorbed->getFirstMedia('poster')?->move($keep, 'poster');
        }
        foreach ($absorbed->getMedia('gallery') as $media) {
            $media->move($keep, 'gallery');
        }
    }

    /**
     * Sposta le righe di una tabella da `from` a `to` rispettando un vincolo
     * unico: le righe che avrebbero una gemella in `to` vengono cancellate.
     *
     * @param  list<string>  $uniqueWith
     */
    private function repointUnique(string $table, string $column, object $from, object $to, array $uniqueWith): int
    {
        if ($uniqueWith !== []) {
            DB::table($table)->where($column, $from->getKey())
                ->whereExists(function (QueryBuilder $twin) use ($table, $column, $to, $uniqueWith): void {
                    $twin->from($table.' as twin')->where('twin.'.$column, $to->getKey());
                    foreach ($uniqueWith as $key) {
                        $twin->whereColumn('twin.'.$key, $table.'.'.$key);
                    }
                })->delete();
        }

        return DB::table($table)->where($column, $from->getKey())->update([$column => $to->getKey()]);
    }

    /** @param list<string> $uniqueWith */
    private function repointMorph(string $table, string $morph, Event $from, Event $to, array $uniqueWith): void
    {
        $type = $from->getMorphClass();
        if ($uniqueWith !== []) {
            DB::table($table)->where($morph.'_type', $type)->where($morph.'_id', $from->getKey())
                ->whereExists(function (QueryBuilder $twin) use ($table, $morph, $type, $to, $uniqueWith): void {
                    $twin->from($table.' as twin')->where('twin.'.$morph.'_type', $type)->where('twin.'.$morph.'_id', $to->getKey());
                    foreach ($uniqueWith as $key) {
                        $twin->whereColumn('twin.'.$key, $table.'.'.$key);
                    }
                })->delete();
        }
        DB::table($table)->where($morph.'_type', $type)->where($morph.'_id', $from->getKey())->update([$morph.'_id' => $to->getKey()]);
    }

    /** Le righe giornaliere si sommano quando lo stesso giorno esiste già. */
    private function sumDaily(string $table, string $column, object $from, object $to): void
    {
        $rows = DB::table($table)->where($column, $from->getKey())->get();
        foreach ($rows as $row) {
            $twin = DB::table($table)->where($column, $to->getKey())->where('date', $row->date)->first();
            if ($twin === null) {
                DB::table($table)->where('id', $row->id)->update([$column => $to->getKey()]);

                continue;
            }
            $sums = [];
            foreach ((array) $row as $field => $value) {
                if (in_array($field, ['id', $column, 'date', 'created_at', 'updated_at'], true) || ! is_numeric($value)) {
                    continue;
                }
                $sums[$field] = DB::raw($field.' + '.(int) $value);
            }
            if ($sums !== []) {
                DB::table($table)->where('id', $twin->id)->update($sums);
            }
            DB::table($table)->where('id', $row->id)->delete();
        }
    }
}
