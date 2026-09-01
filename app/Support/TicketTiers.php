<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\TicketTier;
use Illuminate\Database\Eloquent\Collection;

/**
 * **L'unico posto in cui è scritta la regola di risoluzione del listino.**
 *
 * Un evento ha un listino (`ticket_tiers` con `occurrence_id` nullo) e una
 * singola data può averne uno proprio, che **sostituisce** il primo — la
 * stessa relazione che lo schema ha già fra `events.price_*` e
 * `event_occurrences.price_override`. Non c'è fusione per nome: o vale il
 * listino della data, o quello dell'evento.
 *
 * Sito, pannelli, API e dati strutturati passano di qui. Se la regola venisse
 * riscritta in una vista, fra sei mesi la scheda e l'API direbbero due prezzi
 * diversi per la stessa serata.
 */
final class TicketTiers
{
    /**
     * Il listino da mostrare per una data — o quello dell'evento, se la data
     * non è indicata.
     *
     * Le relazioni già caricate vengono usate così come sono: una lista di
     * cinquanta occorrenze non deve fare cinquanta interrogazioni.
     *
     * @return Collection<int, TicketTier>
     */
    public static function for(Event $event, ?EventOccurrence $occurrence = null): Collection
    {
        if ($occurrence !== null) {
            $own = self::sorted(self::load($occurrence));

            if ($own->isNotEmpty()) {
                return $own;
            }
        }

        return self::sorted(self::eventListing($event));
    }

    /**
     * Il prezzo più basso del listino dell'evento, o `null` se nessuna fascia
     * dichiara un prezzo.
     *
     * È il numero che finisce su `events.price_min` (vedi
     * `App\Observers\TicketTierObserver`): la card e il filtro «fino a 10 €»
     * leggono una colonna, non una relazione, perché il filtro è SQL e una
     * derivazione a tempo di render non entrerebbe mai nella `WHERE`.
     */
    public static function lowestPrice(Event $event): ?float
    {
        return self::extreme($event, min(...));
    }

    public static function highestPrice(Event $event): ?float
    {
        return self::extreme($event, max(...));
    }

    /**
     * @param  callable(list<float>): float  $pick
     */
    private static function extreme(Event $event, callable $pick): ?float
    {
        $prices = self::eventListing($event)
            ->reject(static fn (TicketTier $tier): bool => $tier->price === null)
            ->map(static fn (TicketTier $tier): float => (float) $tier->price)
            ->values()
            ->all();

        return $prices === [] ? null : $pick($prices);
    }

    /**
     * @return Collection<int, TicketTier>
     */
    private static function eventListing(Event $event): Collection
    {
        if ($event->relationLoaded('ticketTiers')) {
            /** @var Collection<int, TicketTier> $loaded */
            $loaded = $event->ticketTiers->whereNull('occurrence_id')->values();

            return $loaded;
        }

        return $event->ticketTiers()->forEventListing()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, TicketTier>
     */
    private static function load(EventOccurrence $occurrence): Collection
    {
        return $occurrence->relationLoaded('ticketTiers')
            ? $occurrence->ticketTiers
            : $occurrence->ticketTiers()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, TicketTier>  $tiers
     * @return Collection<int, TicketTier>
     */
    private static function sorted(Collection $tiers): Collection
    {
        /** @var Collection<int, TicketTier> $sorted */
        $sorted = $tiers->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();

        return $sorted;
    }
}
