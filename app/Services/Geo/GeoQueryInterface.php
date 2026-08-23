<?php

declare(strict_types=1);

namespace App\Services\Geo;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Ogni query geospaziale passa da qui: nessun `ST_*` viene scritto a mano
 * altrove. Le coordinate sono sempre `POINT(lng, lat)` con SRID 0, il solo
 * formato che si comporta in modo identico su MariaDB e su MySQL 8+.
 */
interface GeoQueryInterface
{
    /**
     * Restringe ai record entro `$radiusKm` dal punto dato.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  string  $column  colonna POINT, eventualmente qualificata (`venues.location`)
     * @return Builder<TModel>
     */
    public function withinRadius(Builder $query, float $lat, float $lng, float $radiusKm, string $column): Builder;

    /**
     * Restringe ai record dentro il rettangolo dato (ordine dei parametri
     * identico a `bbox=minLng,minLat,maxLng,maxLat` dell'API).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function withinBounds(Builder $query, float $minLng, float $minLat, float $maxLng, float $maxLat, string $column): Builder;

    /**
     * Aggiunge alla select la distanza in metri dal punto dato.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  string  $as  alias della colonna calcolata
     * @return Builder<TModel>
     */
    public function distanceSelect(Builder $query, float $lat, float $lng, string $column, string $as): Builder;
}
