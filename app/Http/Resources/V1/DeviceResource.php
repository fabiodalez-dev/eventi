<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\Device;
use App\Support\Api\ApiDate;

/**
 * Un dispositivo registrato (§15.6).
 *
 * Il token e l'`endpoint` **non escono**: sono le chiavi con cui si spinge una
 * notifica verso quel dispositivo, e una risposta che le restituisse le
 * renderebbe leggibili da qualunque cosa legga il traffico dell'app. Il client
 * li ha già: li ha appena inviati lui.
 */
final class DeviceResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Device $device, string $timezone): array
    {
        return [
            'id' => (int) $device->getKey(),
            'platform' => $device->platform->value,
            'installation_id' => $device->installation_id,
            'app_version' => $device->app_version,
            'locale' => $device->locale,
            'last_seen_at' => ApiDate::instant($device->last_seen_at, $timezone),
            'revoked_at' => ApiDate::instant($device->revoked_at, $timezone),
        ];
    }
}
