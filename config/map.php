<?php

declare(strict_types=1);

/* La mappa pubblica usa uno stile vettoriale: vie ed etichette restano nitide
 * a ogni zoom e non esiste il limite delle vecchie tessere raster Esri, che
 * oltre il livello 16 mostravano "Map data not yet available". */
return [
    'light_style_url' => env('MAP_LIGHT_STYLE_URL', 'https://tiles.openfreemap.org/styles/positron'),

    'style_url' => env('MAP_STYLE_URL', 'https://tiles.openfreemap.org/styles/dark'),

    'max_zoom' => 19,

    /*
     * Lo zoom del riquadro che mostra UN locale, sulla sua scheda e su quella
     * dei suoi eventi. Quindici e' il livello a cui si leggono i nomi delle
     * vie attorno: piu' largo e non si capisce dove sia, piu' stretto e si
     * perde il rione.
     */
    'venue_zoom' => 15,

    /*
     * Le tessere del **pannello**, che è chiaro.
     *
     * Quelle del sito sono scure perché il sito è nero: sul fondo chiaro
     * dell'amministrazione diventano una macchia, e il segnaposto lime ci
     * sparisce dentro. Stesso fornitore, stessa proiezione, stessa
     * attribuzione — solo la versione chiara.
     */
    'tiles_url_light' => env('MAP_TILES_URL_LIGHT', 'https://services.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}'),

    'attribution' => env('MAP_ATTRIBUTION', '© OpenFreeMap · © OpenStreetMap contributors'),

    'attribution_url' => 'https://www.openstreetmap.org/copyright',

    /*
     * Quanti marcatori si spediscono al massimo per una singola inquadratura.
     * La mappa raggruppa, quindi il numero non è un limite visivo ma di peso:
     * oltre questa soglia si guadagna poco e si spende molto.
     */
    'max_markers' => 500,

    /*
     * Colore di ripiego per le categorie che non ne dichiarano uno: l'accento
     * del sito, non un viola che in questa tavolozza non esiste.
     */
    'fallback_color' => '#ccff00',

    /*
     * Scarto minimo, in gradi, perché lo spostamento della mappa venga
     * considerato un vero cambio di inquadratura: sotto questa soglia il
     * pulsante "Cerca in quest'area" non compare, altrimenti apparirebbe a
     * ogni tremolio del dito.
     */
    'pan_threshold' => 0.002,

];
