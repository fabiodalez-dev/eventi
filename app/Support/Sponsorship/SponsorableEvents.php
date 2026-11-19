<?php

declare(strict_types=1);

namespace App\Support\Sponsorship;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\SponsorshipStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gli eventi che un committente può mettere in evidenza: i suoi, pubblicati,
 * con almeno una data ancora da fare (o in corso) che non sia annullata o
 * rinviata.
 *
 * La regola sta in un punto solo perché la usano tre strade diverse — la
 * scelta a card del pannello, la scelta con un'abilitazione già attiva e la
 * sincronizzazione automatica — e ognuna, da sola, l'aveva dimenticata: le
 * campagne nascevano anche su bozze ed eventi passati, non comparivano mai e
 * gonfiavano i conteggi della redazione.
 */
final class SponsorableEvents
{
    /**
     * Gli eventi del committente che si possono sponsorizzare, con la
     * prossima data già calcolata (`next_starts_at`).
     *
     * @return Builder<Event>
     */
    public static function of(Venue|Organizer $sponsor, ?CarbonImmutable $now = null): Builder
    {
        $query = Event::query();
        $sponsor instanceof Venue
            ? $query->where('events.venue_id', $sponsor->getKey())
            : $query->where('events.organizer_id', $sponsor->getKey());

        return self::scope($query, $now);
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public static function scope(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now('UTC');

        return $query->where('events.status', EventStatus::Published)
            ->whereHas('occurrences', fn (Builder $dates) => self::futureDates($dates, $now))
            ->addSelect(['next_starts_at' => fn ($next) => $next->from('event_occurrences')
                ->select('starts_at')
                ->whereColumn('event_occurrences.event_id', 'events.id')
                ->whereIn('status', [OccurrenceStatus::Scheduled->value, OccurrenceStatus::SoldOut->value])
                ->where('effective_ends_at', '>', $now)
                ->whereNull('deleted_at')
                ->orderBy('starts_at')
                ->limit(1)]);
    }

    public static function isSponsorable(Event $event, ?CarbonImmutable $now = null): bool
    {
        return $event->status === EventStatus::Published
            && ! $event->trashed()
            && $event->occurrences()->where(fn (Builder $dates) => self::futureDates($dates, $now ?? CarbonImmutable::now('UTC')))->exists();
    }

    public static function belongsTo(Event $event, Venue|Organizer $sponsor): bool
    {
        return $sponsor instanceof Venue
            ? (int) $event->venue_id === (int) $sponsor->getKey()
            : (int) $event->organizer_id === (int) $sponsor->getKey();
    }

    /**
     * Gli eventi, fra quelli indicati, che hanno già una campagna in corso o
     * programmata: nella scelta a card prendono il distintivo «già
     * sponsorizzato».
     *
     * @param  list<int>  $eventIds
     * @return list<int>
     */
    public static function alreadySponsored(array $eventIds, ?CarbonImmutable $now = null): array
    {
        if ($eventIds === []) {
            return [];
        }

        return Sponsorship::query()->whereIn('event_id', $eventIds)
            ->where('status', SponsorshipStatus::Active)
            ->where('ends_at', '>=', $now ?? CarbonImmutable::now('UTC'))
            ->distinct()->pluck('event_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  Builder<EventOccurrence>  $dates
     * @return Builder<EventOccurrence>
     */
    private static function futureDates(Builder $dates, CarbonImmutable $now): Builder
    {
        return $dates->whereIn('status', [OccurrenceStatus::Scheduled, OccurrenceStatus::SoldOut])
            ->where('effective_ends_at', '>', $now);
    }
}
