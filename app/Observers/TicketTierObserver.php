<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Event;
use App\Models\TicketTier;
use App\Support\TicketTiers;

/**
 * Il listino detta il prezzo dell'evento, non viceversa.
 *
 * Quando un evento ha delle fasce, `events.price_min` e `events.price_max`
 * vengono ricalcolati da qui a ogni salvataggio. **Non è una comodità: è
 * l'unico modo perché il filtro funzioni.** «Gratis», «fino a 10 €», «fino a
 * 20 €» di §11.3 sono una `WHERE` su `events.price_min`, e un minimo derivato
 * al momento del render resterebbe fuori dalla query — la card direbbe «da
 * 12 €» e il filtro «fino a 20 €» non troverebbe l'evento.
 *
 * Il minimo si calcola su **tutte** le fasce con un prezzo, comprese quelle
 * esaurite: il disegno di riferimento fa lo stesso, e ha ragione — «Biglietti
 * da 49 €» con il parterre esaurito resta l'informazione utile, mentre far
 * salire il prezzo in vetrina ogni volta che una fascia finisce
 * racconterebbe un rincaro che non è avvenuto.
 *
 * Un evento **senza** fasce non viene toccato: i suoi `price_min`/`price_max`
 * restano quelli scritti a mano, ed è la ragione per cui l'aggiunta delle
 * fasce non rompe nulla di quanto esisteva prima.
 */
final class TicketTierObserver
{
    public function saved(TicketTier $tier): void
    {
        $this->sync($tier);
    }

    public function deleted(TicketTier $tier): void
    {
        $this->sync($tier);
    }

    /**
     * Le fasce di una **singola data** non toccano il prezzo dell'evento: è
     * un'eccezione a quella serata, e alzarla a listino generale falserebbe la
     * card di tutte le altre.
     */
    private function sync(TicketTier $tier): void
    {
        if ($tier->occurrence_id !== null) {
            $tier->occurrence?->touch();

            return;
        }

        $event = $tier->relationLoaded('event') ? $tier->event : Event::find($tier->event_id);

        if (! $event instanceof Event) {
            return;
        }

        $event->unsetRelation('ticketTiers');

        $min = TicketTiers::lowestPrice($event);

        if ($min === null) {
            $event->touch();

            return;
        }

        $event->forceFill([
            'price_min' => $min,
            'price_max' => TicketTiers::highestPrice($event),
        ])->save();
    }
}
