<?php

declare(strict_types=1);

namespace App\Filament\Venue\Support;

use App\Models\City;
use App\Models\Venue;
use Filament\Facades\Filament;

/**
 * Il locale su cui si sta lavorando, letto una volta sola e sempre allo stesso
 * modo.
 *
 * Filament lo tiene già da parte dopo `IdentifyTenant`; qui si aggiunge solo
 * la certezza del tipo e la scorciatoia verso la città, che serve a ogni
 * pagina del pannello per una ragione sola: **il fuso**. Ogni ora mostrata o
 * digitata in `/gestione` è ora locale della città, mai ora del server (§8.1).
 */
final class CurrentVenue
{
    public static function get(): Venue
    {
        $tenant = Filament::getTenant();

        abort_unless($tenant instanceof Venue, 403);

        return $tenant->relationLoaded('city') ? $tenant : $tenant->load('city');
    }

    /**
     * La città non è mai nulla: `venues.city_id` è una chiave obbligatoria
     * con vincolo `RESTRICT`.
     */
    public static function city(): City
    {
        return self::get()->city;
    }

    public static function timezone(): string
    {
        return self::city()->timezone;
    }
}
