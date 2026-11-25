<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Event;
use App\Queries\EventChoices;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le voci del selettore di eventi dei pannelli (`EventSelect`).
 *
 * Ogni voce è la stessa riga dappertutto: locandina, titolo, e sotto «sab 22
 * nov, 21:00 · Officina dei Portici» — mai un ID, uno slug o una data in
 * cifre. Le voci si dividono in «Prossimi» (dalla data più vicina) e «Passati»
 * (dal più recente).
 *
 * **Una lettura per gruppo, non una per voce.** La prossima data, l'ultima
 * passata e quante date restano arrivano come sotto-query nella stessa
 * lettura degli eventi; locandina, locale e fuso della città si caricano
 * insieme. Il numero di query non cambia fra 5 e 500 eventi.
 *
 * La query che si riceve è quella che chi guarda può già vedere:
 * l'autorizzazione resta dov'è, qui si legge e basta.
 */
final class EventOptions
{
    public const UPCOMING_LIMIT = EventChoices::UPCOMING_LIMIT;

    public const PAST_LIMIT = EventChoices::PAST_LIMIT;

    public const SEARCH_LIMIT = EventChoices::SEARCH_LIMIT;

    /**
     * @param  Builder<Event>  $events
     * @return array<string, array<int|string, string>>
     */
    public static function grouped(Builder $events, ?string $search = null, bool $showVenue = true, bool $upcomingOnly = false): array
    {
        // La lettura è quella del foglio «Scegli un evento» dell'app: `EventChoices`.
        $choices = EventChoices::grouped($events, $search, $upcomingOnly);
        $groups = [__('pannelli.select.upcoming') => self::labels($choices['upcoming'], $showVenue)];

        if ($choices['past'] !== null) {
            $groups[__('pannelli.select.past')] = self::labels($choices['past'], $showVenue);
        }

        return array_filter($groups);
    }

    /**
     * La riga del valore già scelto, oppure null se l'evento non è fra quelli
     * che la query lascia vedere: così un valore scritto a mano non passa.
     *
     * @param  Builder<Event>  $events
     */
    public static function labelFor(Builder $events, mixed $value, bool $showVenue = true): ?string
    {
        if (blank($value) || ! is_scalar($value)) {
            return null;
        }

        $event = self::withDates($events, CarbonImmutable::now('UTC'))->whereKey($value)->first();

        return $event instanceof Event ? self::render($event, $showVenue) : null;
    }

    /** La riga di un evento già caricato (le date si leggono se mancano). */
    public static function label(Event $event, bool $showVenue = true): string
    {
        if (! array_key_exists('next_starts_at', $event->getAttributes())) {
            $loaded = self::withDates(Event::query()->withoutGlobalScopes(), CarbonImmutable::now('UTC'))
                ->whereKey($event->getKey())
                ->first();
            $event = $loaded instanceof Event ? $loaded : $event;
        }

        return self::render($event, $showVenue);
    }

    /**
     * Il titolo nudo di un evento della query, per gli indicatori dei filtri.
     *
     * @param  Builder<Event>  $events
     */
    public static function title(Builder $events, mixed $value): ?string
    {
        if (blank($value) || ! is_scalar($value)) {
            return null;
        }

        $title = (clone $events)->whereKey($value)->value($events->getModel()->qualifyColumn('title'));

        return is_string($title) ? $title : null;
    }

    /**
     * @param  Builder<Event>  $events
     * @return Builder<Event>
     */
    private static function withDates(Builder $events, CarbonImmutable $now): Builder
    {
        return EventChoices::withDates($events, $now);
    }

    /**
     * @param  iterable<Event>  $events
     * @return array<int|string, string>
     */
    private static function labels(iterable $events, bool $showVenue): array
    {
        $labels = [];

        foreach ($events as $event) {
            $labels[$event->getKey()] = self::render($event, $showVenue);
        }

        return $labels;
    }

    private static function render(Event $event, bool $showVenue): string
    {
        $next = $event->getAttribute('next_starts_at');
        $upcoming = (int) $event->getAttribute('upcoming_count');

        return view('filament.partials.event-option', [
            'event' => $event,
            'when' => $next ?? $event->getAttribute('last_starts_at'),
            'more' => max(0, $upcoming - 1),
            'venue' => $showVenue ? $event->venue?->name : null,
        ])->render();
    }
}
