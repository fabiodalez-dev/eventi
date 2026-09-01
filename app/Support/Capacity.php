<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\EventOccurrence;

/**
 * Capienza e posti rimasti di una data, nella forma in cui si mostrano.
 *
 * `event_occurrences.capacity_left` esiste dal primo giorno e non è mai stato
 * mostrato: da solo è un numero senza scala — «41 posti rimasti» non dice se è
 * tanto o poco. Il totale lo dà `event_occurrences.capacity` quando la serata
 * ne ha uno proprio (una sala da 400 messa a platea seduta ne fa 180) e
 * altrimenti `venues.capacity`.
 *
 * **Senza totale non si disegna la barra.** Una percentuale calcolata su una
 * capienza inventata è peggio di nessuna percentuale: `percentSold()`
 * restituisce `null`, e chi disegna mostra il solo conteggio.
 */
final readonly class Capacity
{
    private function __construct(
        public ?int $total,
        public int $left,
    ) {}

    /**
     * `null` quando il locale non ha dichiarato quanti posti restano: non si
     * inventa un numero e non si scrive «posti disponibili» per riempire la
     * riga (§8.6).
     */
    public static function for(EventOccurrence $occurrence): ?self
    {
        $left = $occurrence->capacity_left;

        if ($left === null) {
            return null;
        }

        $total = $occurrence->capacity ?? $occurrence->event?->venue?->capacity;

        if ($total !== null && $total <= 0) {
            $total = null;
        }

        return new self($total, max(0, $left));
    }

    public function isSoldOut(): bool
    {
        return $this->left === 0;
    }

    /**
     * La quota già venduta, da 0 a 100. `null` senza un totale credibile —
     * compreso il caso in cui i posti rimasti superino la capienza
     * dichiarata, che significa che uno dei due numeri è vecchio.
     */
    public function percentSold(): ?int
    {
        if ($this->total === null || $this->left > $this->total) {
            return null;
        }

        return (int) round(100 * (1 - $this->left / $this->total));
    }
}
