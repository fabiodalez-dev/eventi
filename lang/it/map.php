<?php

declare(strict_types=1);

/*
 * La mappa degli eventi (§11.6) e il "vicino a me" (§11.7).
 */
return [

    'title' => 'Mappa degli eventi',

    'meta' => [
        'title' => 'Mappa degli eventi a :city',
        'description' => 'Dove succedono le cose a :city e provincia: sposta la mappa e cerca in quest\'area.',
    ],

    'label' => 'Mappa degli eventi, con i locali che hanno date in programma',

    'search_here' => 'Cerca in quest\'area',
    'searching' => 'Cerco…',
    'truncated' => 'Ci sono più locali di quelli disegnati: restringi la zona o accendi un filtro.',
    'legend' => 'Colori delle categorie',

    'venue_events' => ':count data in programma|:count date in programma',
    'venue_page' => 'Vai alla scheda del locale',
    'close_sheet' => 'Chiudi la scheda',
    'sheet_label' => 'Eventi del locale selezionato',
    'unavailable' => 'La mappa non si è caricata. Qui sotto trovi gli stessi eventi in elenco.',
    'fallback_title' => 'Eventi in questa zona',
    'fallback_link' => 'Sfoglia la lista completa',

    'attribution' => 'Dati cartografici © :osm contributors, licenza :license · tessere :tiles',
    'tiles' => 'OpenFreeMap',

    /* §11.7: la posizione si chiede quando serve, dicendo perché. */
    'near' => [
        'title' => 'Vicino a me',
        'body' => 'Chiediamo la posizione solo adesso e solo per ordinare i risultati: non viene salvata da nessuna parte e sparisce chiudendo la pagina.',
        'allow' => 'Usa la mia posizione',
        'radius' => 'Entro :km km',
        'radius_label' => 'Raggio di ricerca',
        'denied' => 'Senza posizione restano tutti gli eventi della zona.',
        'active' => 'Risultati entro :km km dalla tua posizione.',
        'clear' => 'Togli la posizione',
    ],

];
