<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\PriceType;
use App\Models\Event;
use App\Models\EventOccurrence;

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
        $override = is_array($occurrence?->price_override) ? $occurrence->price_override : [];

        $type = isset($override['price_type']) && is_string($override['price_type'])
            ? PriceType::tryFrom($override['price_type']) ?? $event->price_type
            : $event->price_type;

        $min = $override['price_min'] ?? $event->price_min;
        $max = $override['price_max'] ?? $event->price_max;

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
