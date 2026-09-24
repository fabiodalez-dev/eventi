<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\ContentMetric;
use App\Enums\VenueStatus;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventShareLink;
use App\Models\Organizer;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Generator;

final class EventShares
{
    public const CHANNELS = ['native', 'whatsapp', 'telegram', 'email'];

    /**
     * Il prefisso dei canali scelti dal locale. Serve a non confondere un
     * canale chiamato «email» con il pulsante email: i conteggi finiscono
     * nella stessa colonna, e senza un segno che li distingua un'etichetta
     * libera potrebbe riscrivere la storia di un canale di sistema.
     */
    public const CUSTOM_PREFIX = 'c-';

    /**
     * Quanti canali propri per locale. Servono a distinguere volantino, radio
     * e partner: oltre una dozzina la tabella smette di essere un elenco di
     * canali e diventa un archivio di campagne, che è un'altra cosa.
     */
    public const MAX_CUSTOM = 12;

    /** @return array<string, array{url: string, metric: string}> */
    public function links(Event $event, ?EventOccurrence $occurrence): array
    {
        abort_if($occurrence !== null && $occurrence->event_id !== $event->id, 404);

        return $this->linksFor($event, $occurrence);
    }

    /**
     * I link tracciati della scheda del locale. La pagina è servita da cache:
     * `createOrFirst` garantisce che una ricostruzione restituisca gli stessi
     * codici, altrimenti ogni rigenerazione azzererebbe di fatto i conteggi.
     *
     * @return array<string, array{url: string, metric: string}>
     */
    public function venueLinks(Venue $venue): array
    {
        return $this->linksFor($venue, null);
    }

    /** @return array<string, array{url: string, metric: string}> */
    private function linksFor(Event|Venue $target, ?EventOccurrence $occurrence): array
    {
        $existing = EventShareLink::query()
            ->where($target instanceof Venue ? 'venue_id' : 'event_id', $target->getKey())
            ->when(! $target instanceof Venue, fn ($query) => $query->where('occurrence_id', $occurrence?->id))
            ->get()->keyBy('channel');
        $links = [];
        foreach (self::CHANNELS as $channel) {
            $link = $existing->get($channel) ?? $this->create($target, $occurrence, $channel);
            $links[$channel] = [
                'url' => route('event-shares.open', ['code' => $link->code]),
                'metric' => route('event-shares.share', ['code' => $link->code], false),
            ];
        }

        return $links;
    }

    private function create(Event|Venue $target, ?EventOccurrence $occurrence, string $channel): EventShareLink
    {
        $isVenue = $target instanceof Venue;
        // The non-null key also serializes concurrent requests for series links.
        // Venue keys start with a letter, event keys with a digit: no overlap.
        $key = $isVenue
            ? 'v:'.$target->getKey().':'.$channel
            : $target->getKey().':'.($occurrence->id ?? 0).':'.$channel;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return EventShareLink::query()->createOrFirst(['target_key' => $key], [
                    'code' => Str::random(7),
                    'event_id' => $isVenue ? null : $target->getKey(),
                    'venue_id' => $isVenue ? $target->getKey() : null,
                    'occurrence_id' => $isVenue ? null : $occurrence?->id,
                    'channel' => $channel,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 4) {
                    throw $exception;
                }
            }
        }
        throw new \LogicException('Unreachable');
    }

    /**
     * Un canale con il nome che gli dà il locale, sulla propria scheda o su un
     * proprio evento. L'etichetta si normalizza in uno slug perché è una
     * chiave di conteggio, non un titolo: «Volantino estate» e «volantino
     * ESTATE» sono lo stesso canale, e chi stampa non deve accorgersene.
     */
    public function addChannel(Venue $venue, string $label, ?Event $event = null): EventShareLink
    {
        abort_if($event !== null && $event->venue_id !== $venue->getKey(), 403);
        // Il taglio può cadere su un trattino: uno slug che finisce con il
        // separatore è lo stesso canale scritto male, non un canale diverso.
        $slug = self::CUSTOM_PREFIX.Str::of($label)->slug()->limit(38 - strlen(self::CUSTOM_PREFIX), '')->trim('-')->value();
        abort_if(mb_strlen($slug) < strlen(self::CUSTOM_PREFIX) + 2, 422);
        $links = $this->customLinks($venue);
        abort_if($links->count() >= self::MAX_CUSTOM && ! $links->contains('channel', $slug), 422);

        return $this->create($event ?? $venue, null, $slug);
    }

    /** @return Collection<int, EventShareLink> */
    public function customLinks(Venue $venue): Collection
    {
        return EventShareLink::query()->where('channel', 'like', self::CUSTOM_PREFIX.'%')
            ->where(fn ($query) => $query->where('venue_id', $venue->getKey())
                ->orWhereIn('event_id', Event::query()->where('venue_id', $venue->getKey())->select('id')))
            ->orderBy('id')->get();
    }

    /**
     * I canali su cui si può filtrare nel pannello: i quattro di sistema più
     * quelli propri di chi sta guardando. Restano scoperti alla redazione, che
     * vede tutto: a un locale i nomi dei canali di un altro non arrivano mai,
     * e un nome di canale dice quanto una campagna.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        $tenant = Filament::getTenant();
        $custom = EventShareLink::query()->where('channel', 'like', self::CUSTOM_PREFIX.'%')
            ->when($tenant instanceof Venue, fn ($query) => $query->where(fn ($owned) => $owned->where('venue_id', $tenant->getKey())
                ->orWhereIn('event_id', Event::query()->where('venue_id', $tenant->getKey())->select('id'))))
            ->when($tenant instanceof Organizer, fn ($query) => $query->whereIn('event_id',
                Event::query()->where('organizer_id', $tenant->getKey())->select('id')))
            ->distinct()->orderBy('channel')->limit(50)->pluck('channel')->all();

        return [...self::CHANNELS, ...$custom];
    }

    /** L'etichetta di un canale: tradotta se è di sistema, ricostruita dallo slug se è del locale. */
    public static function channelLabel(string $channel): string
    {
        return str_starts_with($channel, self::CUSTOM_PREFIX)
            ? Str::headline(substr($channel, strlen(self::CUSTOM_PREFIX)))
            : __('event-shares.channels.'.$channel);
    }

    /**
     * Le righe che il locale vede nel pannello: i propri link stampabili con i
     * totali di sempre. Il QR nasce dallo stesso codice breve, quindi conta
     * insieme a tutto il resto invece di essere un secondo indirizzo da
     * riconciliare a mano.
     *
     * @return list<array<string, mixed>>
     */
    public function printableLinks(Venue $venue): array
    {
        // I quattro di sistema esistono dalla prima visita alla scheda: chi
        // apre il pannello prima che qualcuno passi di lì deve comunque
        // trovare il QR della propria pagina.
        $this->venueLinks($venue);
        $links = EventShareLink::query()->with('event:id,title')
            ->where(fn ($query) => $query->where('venue_id', $venue->getKey())
                ->orWhere(fn ($custom) => $custom->where('channel', 'like', self::CUSTOM_PREFIX.'%')
                    ->whereIn('event_id', Event::query()->where('venue_id', $venue->getKey())->select('id'))))
            ->orderBy('id')->get();
        $totals = DB::table('event_share_daily')->whereIn('share_link_id', $links->modelKeys())
            ->selectRaw('share_link_id, SUM(shares) as shares, SUM(clicks) as clicks')
            ->groupBy('share_link_id')->get()->keyBy('share_link_id');

        return $links->map(fn (EventShareLink $link): array => [
            'code' => $link->code,
            'channel' => self::channelLabel($link->channel),
            'target' => $link->event?->title ?? $venue->name,
            'url' => route('event-shares.open', ['code' => $link->code]),
            'qr' => route('event-shares.qr', ['code' => $link->code]),
            'download' => route('event-shares.qr', ['code' => $link->code, 'scarica' => 1]),
            'shares' => (int) ($totals->get($link->getKey())?->shares ?? 0),
            'clicks' => (int) ($totals->get($link->getKey())?->clicks ?? 0),
        ])->all();
    }

    /** Il QR del codice breve, in vettoriale perché finisce su carta. */
    public function qr(EventShareLink $link): string
    {
        return (string) (new Generator)->format('svg')->size(512)->margin(1)->errorCorrection('M')
            ->generate(route('event-shares.open', ['code' => $link->code]));
    }

    public function resolve(string $code): EventShareLink
    {
        $link = EventShareLink::query()->with(['event.city', 'occurrence', 'venue.city'])->where('code', $code)->firstOrFail();
        if ($link->venue_id !== null) {
            $venue = $link->venue;
            /* Un locale sospeso resta raggiungibile dal sito ma non è
               pubblicità: un QR stampato è pubblicità, e smette di valere. */
            abort_unless($venue !== null && $venue->status === VenueStatus::Approved && $venue->city->is_active, 404);

            return $link;
        }
        $event = $link->event;
        abort_unless($event !== null && $event->city->is_active
            && Event::query()->readable()->whereKey($event->id)->exists(), 404);
        if ($link->occurrence_id !== null) {
            $visible = EventOccurrenceQuery::for($event->city)->forEvent($event)->forOccurrence($link->occurrence_id)->upcoming()->get()->contains('id', $link->occurrence_id)
                || EventOccurrenceQuery::archiveFor($event->city)->forEvent($event)->forOccurrence($link->occurrence_id)->past()->get()->contains('id', $link->occurrence_id);
            abort_unless($visible, 404);
        }

        return $link;
    }

    public function destination(EventShareLink $link): string
    {
        $subject = $link->event ?? $link->venue;
        abort_if($subject === null, 404);
        $default = City::query()->active()->orderBy('id')->value('id');
        $prefix = $default === $subject->city_id ? '' : 'city.';
        $parameters = $link->venue !== null ? ['slug' => $link->venue->slug] : ['slug' => $link->event->slug];
        if ($prefix !== '') {
            $parameters['city'] = $subject->city->slug;
        }
        if ($link->venue !== null) {
            return route($prefix.'venues.show', $parameters, false);
        }
        if ($link->occurrence !== null) {
            $parameters['occurrence'] = $link->occurrence->url_number;
        }

        // Relative, generated routes only: no caller-controlled redirect target.
        return route($prefix.($link->occurrence !== null ? 'events.occurrence' : 'events.show'), $parameters, false);
    }

    public function record(EventShareLink $link, bool $share): void
    {
        $subject = $link->event ?? $link->venue;
        abort_if($subject === null, 404);
        $column = $share ? 'shares' : 'clicks';
        DB::transaction(function () use ($link, $subject, $column, $share): void {
            DB::table('event_share_daily')->upsert([[
                'share_link_id' => $link->id,
                'date' => CarbonImmutable::now($subject->city->timezone)->toDateString(),
                $column => 1,
            ]], ['share_link_id', 'date'], [$column => DB::raw($column.' + 1')]);
            if ($share) {
                app(RecordContentMetric::class)->record($link->event !== null ? 'event' : 'venue',
                    $subject->id, ContentMetric::Shares, $link->occurrence_id);
            }
        });
    }
}
