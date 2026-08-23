<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Support\Api\ApiDate;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Una riga dell'archivio in-app (§15.6).
 *
 * L'archivio esiste **sempre**, qualunque sia il canale scelto in fondo alla
 * catena: è il posto dove ritrovare una notifica letta di sfuggita e chiusa.
 * D8 lo ha promosso da comodità a secondo canale dopo l'esclusione del Web
 * Push.
 *
 * `data` esce così com'è stato scritto da chi ha inviato: contiene il titolo,
 * il testo e il collegamento profondo che §15.4 pretende su ogni notifica.
 */
final class NotificationResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(DatabaseNotification $notification, string $timezone): array
    {
        return [
            'id' => (string) $notification->getKey(),
            'type' => (string) $notification->type,
            'data' => $notification->data,
            'read' => $notification->read_at !== null,
            'read_at' => ApiDate::instant($notification->read_at, $timezone),
            'created_at' => ApiDate::attribute($notification, 'created_at', $timezone),
        ];
    }
}
