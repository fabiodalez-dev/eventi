<?php

declare(strict_types=1);

namespace App\Support\Api;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Il formato delle date dell'API (§13): ISO 8601 **con offset**, letto
 * nell'ora della città.
 *
 * Gli istanti (`starts_at`, `updated_at`) si convertono nel fuso della città
 * prima di essere scritti; le **giornate** (`business_date`) no. Una giornata
 * è una data di calendario salvata a mezzanotte UTC: convertirla la farebbe
 * scivolare al giorno prima alle 22:00, ed è un errore che si vede solo in
 * produzione (la stessa trappola di `App\Support\DateFormatter`, D23).
 */
final class ApiDate
{
    public static function instant(?DateTimeInterface $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::instance($value)->setTimezone($timezone)->toIso8601String();
    }

    public static function day(?DateTimeInterface $value): ?string
    {
        return $value?->format('Y-m-d');
    }

    /**
     * `created_at` e `updated_at` si leggono così e non come proprietà: le
     * colonne di questo schema sono `DATETIME` scritte da `$table->datetimes()`
     * (deviazione 11 di `docs/SCHEMA.md`) e l'analisi statica non le conosce
     * come proprietà dei model. Leggerle dall'attributo è anche l'unico modo
     * di dire con certezza che si sta guardando una data e non altro.
     */
    public static function attribute(Model $model, string $key, string $timezone): ?string
    {
        $value = $model->getAttribute($key);

        return $value instanceof DateTimeInterface ? self::instant($value, $timezone) : null;
    }
}
