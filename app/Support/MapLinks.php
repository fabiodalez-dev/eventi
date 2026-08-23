<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Venue;

/**
 * Collegamenti verso le applicazioni di navigazione (§11.5, "indicazioni con
 * deep link a Google e Apple Maps") e verso la mappa statica di OpenStreetMap.
 *
 * I due servizi hanno formati diversi e nessuno dei due è opzionale: su iPhone
 * il link di Google apre il browser, su Android quello di Apple non apre nulla.
 * Si offrono entrambi e sceglie chi legge.
 */
final class MapLinks
{
    public static function google(Venue $venue): string
    {
        return 'https://www.google.com/maps/dir/?'.http_build_query([
            'api' => '1',
            'destination' => self::coordinates($venue),
        ]);
    }

    /**
     * Apple Maps: `daddr` accetta le coordinate e `q` l'etichetta mostrata.
     */
    public static function apple(Venue $venue): string
    {
        return 'https://maps.apple.com/?'.http_build_query([
            'daddr' => self::coordinates($venue),
            'q' => $venue->name,
        ]);
    }

    /**
     * La mappa di OpenStreetMap centrata sul locale: è la cartografia che il
     * piè di pagina attribuisce, e non richiede alcun servizio di terze parti
     * per essere linkata.
     */
    public static function openStreetMap(Venue $venue, int $zoom = 17): string
    {
        return sprintf(
            'https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=%d/%s/%s',
            self::latitude($venue),
            self::longitude($venue),
            $zoom,
            self::latitude($venue),
            self::longitude($venue),
        );
    }

    /**
     * Riquadro incorporabile di OpenStreetMap: un `iframe` senza script di
     * terze parti, con il marcatore sul locale.
     */
    public static function embed(Venue $venue, float $span = 0.006): string
    {
        $lat = (float) self::latitude($venue);
        $lng = (float) self::longitude($venue);

        return 'https://www.openstreetmap.org/export/embed.html?'.http_build_query([
            'bbox' => sprintf('%F,%F,%F,%F', $lng - $span, $lat - $span / 2, $lng + $span, $lat + $span / 2),
            'layer' => 'mapnik',
            'marker' => sprintf('%F,%F', $lat, $lng),
        ]);
    }

    private static function coordinates(Venue $venue): string
    {
        return self::latitude($venue).','.self::longitude($venue);
    }

    private static function latitude(Venue $venue): string
    {
        return (string) $venue->lat;
    }

    private static function longitude(Venue $venue): string
    {
        return (string) $venue->lng;
    }
}
