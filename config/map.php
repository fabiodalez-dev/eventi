<?php

declare(strict_types=1);

/*
 * La mappa pubblica (§11.6).
 *
 * **Una mappa scura vera, non una mappa chiara rovesciata.**
 *
 * Il disegno adottato (D46) vuole una mappa scura, e per un po' l'ho ottenuta
 * prendendo le tessere standard di OpenStreetMap e applicando
 * `filter: invert(1)`. Non funziona, e il motivo vale la pena di scriverlo:
 * **l'inversione ribalta la gerarchia invece di scurirla**. Sulle tessere OSM
 * il fondo e' beige chiaro (#f2efe9) e le strade sono bianche (#ffffff);
 * invertiti, il fondo diventa quasi nero e le strade diventano nero PIENO —
 * cioe' piu' scure del fondo su cui dovrebbero risaltare. Le etichette, che
 * erano nere, diventano bianche e restano leggibili: da qui l'effetto strano
 * di una mappa in cui si leggono i nomi dei paesi ma la rete stradale e'
 * sparita. Nessun filtro CSS puo' rimettere a posto quella gerarchia, perche'
 * l'inversione la ribalta per costruzione.
 *
 * Servivano quindi tessere disegnate scure in partenza, raster (la mappa sta
 * su Leaflet) e senza chiave di accesso — un sito di citta' non deve dipendere
 * da un contratto per mostrare dove sono i locali. Il campo e' piu' stretto di
 * quanto sembri: le «dark matter» di CARTO stampano «API KEY REQUIRED» in
 * filigrana su ogni tessera a chi non si registra (caricano, e sono
 * inservibili); Stamen e' passato sotto Stadia, che una chiave la chiede;
 * Wikimedia serve solo i propri progetti; OpenFreeMap ha solo vettoriali.
 *
 * Restano le «World Dark Gray Canvas» di Esri: grigio scuro, strade chiare,
 * niente chiave, niente filigrane. Sono costruite anche su dati OpenStreetMap,
 * e l'attribuzione li nomina entrambi.
 *
 * L'attribuzione e' una condizione di licenza, non un ringraziamento, e **deve
 * dire il vero**: quando le tessere cambiano fornitore cambia con loro. Ha
 * gia' continuato a citare OpenFreeMap dopo un cambio, ed e' il modo piu'
 * silenzioso di violare una licenza.
 */
return [

    /*
     * Il modello delle tessere raster. `{z}/{x}/{y}` li sostituisce Leaflet;
     * `{s}` e' il sottodominio e `{r}` diventa `@2x` sugli schermi fitti.
     *
     * ATTENZIONE: qui va un modello di tessere, non un indirizzo di stile.
     * Uno stile MapLibre (`.../styles/liberty`) restituisce un JSON: Leaflet
     * lo chiede come immagine, riceve un documento, e la mappa resta vuota
     * senza un solo errore in console. E' successo, e la diagnosi e' costata
     * piu' della correzione.
     */
    'tiles_url' => env('MAP_TILES_URL', 'https://services.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}'),

    /*
     * I sottodomini di distribuzione, per i fornitori che li usano. Il modello
     * qui sopra non contiene `{s}`, quindi questo valore resta inerte finche'
     * non si passa a un fornitore che li richiede.
     */
    'tiles_subdomains' => env('MAP_TILES_SUBDOMAINS', 'abc'),

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

    'attribution' => env('MAP_ATTRIBUTION', '© OpenStreetMap contributors'),

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
