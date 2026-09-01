<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\TicketTier;

/**
 * Una fascia di biglietto in API: **sola lettura**.
 *
 * Non c'è un `occurrence_id` nella risposta di proposito: chi legge riceve il
 * listino **già risolto** per la data che ha chiesto (vedi
 * `App\Support\TicketTiers`), e sapere se quella riga venga dall'evento o
 * dalla singola serata è un dettaglio interno che non cambia nulla per chi
 * deve disegnare una tabella.
 *
 * Lo stato è per fascia, non per data: un client che vede `sold_out` qui sa
 * che è finito quel settore, mentre lo stato della serata resta in
 * `status` dell'occorrenza.
 */
final class TicketTierResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(TicketTier $tier): array
    {
        return [
            'name' => (string) $tier->name,
            'price' => $tier->price === null ? null : (float) $tier->price,
            'currency' => $tier->currency === '' ? null : (string) $tier->currency,
            'status' => $tier->status->value,
            'url' => $tier->url,
            'note' => $tier->note,
        ];
    }
}
