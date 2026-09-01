<?php

declare(strict_types=1);

namespace App\Casts;

use App\DTOs\TransitGuide;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Il cast di `venues.transit`: dal JSON della colonna a
 * `App\DTOs\TransitGuide`, e ritorno. In colonna `null` quando non ci sono
 * indicazioni, per la stessa ragione di `AsExternalLinks`.
 *
 * @implements CastsAttributes<TransitGuide, TransitGuide|iterable<mixed>|string|null>
 */
final class AsTransitGuide implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): TransitGuide
    {
        return TransitGuide::fromMixed($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $guide = TransitGuide::fromMixed($value);

        return [$key => $guide->isEmpty() ? null : json_encode($guide->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }
}
