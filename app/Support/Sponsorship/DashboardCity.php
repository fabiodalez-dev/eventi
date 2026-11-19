<?php

declare(strict_types=1);

namespace App\Support\Sponsorship;

use App\Models\City;
use App\Support\CurrentCity;

/**
 * La citta' dei riquadri delle sponsorizzazioni nella dashboard di
 * redazione: la stessa degli altri riquadri (`EditorialWidget`), cioe' la
 * citta' accesa o, se nessuna lo e' ancora, la piu' antica.
 *
 * Prima i riquadri delle sponsorizzazioni contavano tutte le citta' insieme
 * e con i giorni UTC, mentre il resto della dashboard parlava di una sola
 * citta': due numeri accanto che non si potevano confrontare.
 */
final class DashboardCity
{
    public static function resolve(): ?City
    {
        return app(CurrentCity::class)->get() ?? City::query()->orderBy('id')->first();
    }

    public static function timezone(?City $city): string
    {
        $timezone = $city?->timezone;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Rome';
    }
}
