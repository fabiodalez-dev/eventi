<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\City;

/**
 * Comune e quartiere di un locale: gli elenchi chiusi fra cui si sceglie, nel
 * pannello del locale e nell'app di gestione.
 *
 * Stavano dentro i campi Filament (`VenueGeographyFields`); l'app ha bisogno
 * della stessa regola fuori dal pannello, quindi la regola sta qui e i campi
 * la chiamano. Il catalogo dei comuni è della provincia, non della città: per
 * una città della provincia di Padova valgono i 101 comuni padovani; per le
 * altre, finché non hanno un catalogo, vale il nome della città. Il quartiere
 * esiste solo per il comune di Padova, dove è obbligatorio.
 */
final class VenueGeography
{
    /** Il comune che ha i quartieri, e dove il quartiere è obbligatorio. */
    public const DISTRICT_MUNICIPALITY = 'Padova';

    /** @return list<string> */
    public static function municipalities(?City $city): array
    {
        $catalog = $city?->province_code === 'PD' ? 'padova' : $city?->slug;
        /** @var list<string> $names */
        $names = array_values(config()->array('discovery-geography.'.$catalog.'.municipalities', $city !== null ? [$city->name] : []));

        return $names;
    }

    /** @return list<string> */
    public static function districts(): array
    {
        /** @var list<string> $names */
        $names = array_values((array) config('discovery-geography.padova.districts', []));

        return $names;
    }

    public static function hasDistricts(mixed $municipality): bool
    {
        return $municipality === self::DISTRICT_MUNICIPALITY;
    }
}
