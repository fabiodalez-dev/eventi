<?php

declare(strict_types=1);

/*
 * La mappa pubblica (§11.6).
 *
 * Le tessere arrivano da OpenFreeMap, che serve stili vettoriali costruiti su
 * dati OpenStreetMap **senza chiave di accesso**: nessun contratto da firmare,
 * nessun identificativo da tenere in un segreto, nessun conteggio di richieste.
 * L'attribuzione a OpenStreetMap non è un ringraziamento ma una condizione
 * della licenza ODbL, e per questo compare sia sulla mappa sia nel piè di
 * pagina del sito.
 */
return [

    /*
     * Stile vettoriale MapLibre. È un indirizzo di stile completo, non un
     * modello di tessere: MapLibre ne ricava da sé i livelli e i caratteri.
     */
    'style' => env('MAP_TILES_URL', 'https://tiles.openfreemap.org/styles/liberty'),

    'attribution' => env('MAP_ATTRIBUTION', '© OpenStreetMap contributors'),

    'attribution_url' => 'https://www.openstreetmap.org/copyright',

    /*
     * Quanti marcatori si spediscono al massimo per una singola inquadratura.
     * La mappa raggruppa, quindi il numero non è un limite visivo ma di peso:
     * oltre questa soglia si guadagna poco e si spende molto.
     */
    'max_markers' => 500,

    /*
     * Colore di ripiego per le categorie che non ne dichiarano uno.
     */
    'fallback_color' => '#7c3aed',

    /*
     * Scarto minimo, in gradi, perché lo spostamento della mappa venga
     * considerato un vero cambio di inquadratura: sotto questa soglia il
     * pulsante "Cerca in quest'area" non compare, altrimenti apparirebbe a
     * ogni tremolio del dito.
     */
    'pan_threshold' => 0.002,

];
