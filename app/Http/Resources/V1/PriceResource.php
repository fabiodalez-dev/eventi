<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\PriceType;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Support\DeclaredCosts;
use App\Support\TicketTiers;

/**
 * Il prezzo di una data.
 *
 * Il prezzo abita l'evento, ma la singola data può sovrascriverlo
 * (`event_occurrences.price_override`): una serata della rassegna può essere
 * gratuita mentre le altre no. L'ordine di lettura è lo stesso dei dati
 * strutturati del sito — prima l'eccezione della data, poi l'evento — perché
 * pagina e API devono dichiarare lo stesso prezzo.
 */
final class PriceResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Event $event, ?EventOccurrence $occurrence = null): array
    {
        $costs = DeclaredCosts::for($occurrence);
        if ($costs !== null) {
            return [
                'type' => $costs['complete'] && $costs['total_cents'] === 0 ? PriceType::Free->value : PriceType::Ticket->value,
                'min' => $costs['total_cents'] / 100, 'max' => $costs['complete'] ? $costs['total_cents'] / 100 : null,
                'is_partial' => ! $costs['complete'],
                'currency' => $costs['currency'], 'notes' => $costs['complete'] ? __('decision.subtotal') : __('decision.partial'),
                'ticket_url' => $event->ticket_url,
            ];
        }
        $override = is_array($occurrence?->price_override) ? $occurrence->price_override : [];

        $type = isset($override['price_type']) && is_string($override['price_type'])
            ? PriceType::tryFrom($override['price_type']) ?? $event->price_type
            : $event->price_type;

        $min = $override['price_min'] ?? $event->price_min;
        $max = $override['price_max'] ?? $event->price_max;

        /* Una data con un listino tutto suo ha quel prezzo, non quello
           dell'evento: il listino della data **sostituisce** quello
           dell'evento (`App\Support\TicketTiers`), e senza questo la scheda
           della data diceva «10–20 €» sopra a «Prezzi di questa data: 25 €».
           Un prezzo scritto a mano sulla data (`price_override`) vale ancora
           di più, e resta com'è. */
        $range = $occurrence !== null && ! isset($override['price_min']) && ! isset($override['price_max'])
            ? TicketTiers::occurrenceRange($occurrence)
            : null;
        if ($range !== null) {
            [$min, $max] = $range;
            $type = PriceType::Ticket;
        }

        return [
            'type' => $type->value,
            'min' => is_numeric($min) ? (float) $min : null,
            'max' => is_numeric($max) ? (float) $max : null,
            'currency' => $event->currency === '' ? null : (string) $event->currency,
            'notes' => $event->price_notes,
            'ticket_url' => $event->ticket_url,
        ];
    }
}
