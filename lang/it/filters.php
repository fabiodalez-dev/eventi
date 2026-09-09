<?php

declare(strict_types=1);

return [
    'advanced' => 'Filtri avanzati',

    'title' => 'Filtri',
    'open' => 'Filtra',
    'apply' => 'Mostra i risultati',
    'reset' => 'Azzera i filtri',
    'active' => ':count filtro attivo|:count filtri attivi',
    'results' => ':count risultato|:count risultati',

    'date' => [
        'label' => 'Quando',
        'today' => 'Oggi',
        'tonight' => 'Stasera',
        'tomorrow' => 'Domani',
        'weekend' => 'Weekend',
        'week' => 'Questa settimana',
        'range' => 'Intervallo di date',
        'from' => 'Dal',
        'to' => 'Al',
        'any' => 'Tutte le date',
    ],

    'category' => [
        'label' => 'Categoria',
        'any' => 'Tutte le categorie',
    ],

    /* La fascia oraria: mattina, pomeriggio, sera, notte. Il gruppo esisteva
       solo come voce di un ordinamento — qui e' un filtro con la sua
       etichetta, perche' nella colonna ogni gruppo deve dire cosa filtra. */
    'time' => [
        'label' => 'Fascia oraria',
    ],

    'tag' => [
        'label' => 'Tag',
        'any' => 'Tutti i tag',
    ],

    'price' => [
        'label' => 'Prezzo',
        'free' => 'Gratis',
        'donation' => 'Offerta libera',
        'max_10' => 'Fino a 10 €',
        'max_20' => 'Fino a 20 €',
        'any' => 'Qualsiasi prezzo',
    ],

    'time_of_day' => [
        'label' => 'Fascia oraria',
        'any' => 'Tutto il giorno',
    ],

    'place' => [
        'label' => 'Zona',
        'municipality' => 'Comune',
        /* Il quartiere, che dentro un capoluogo è la scala a cui si cerca
           davvero: il comune è lo stesso per tutti e non separa niente. */
        'zone' => 'Quartiere',
        'any_zone' => 'Tutti i quartieri',
        'venue' => 'Locale',
        'any' => 'Tutta la provincia',
    ],

    /*
     * Il raggio scelto dentro il modulo dei filtri. Il resto di "vicino a me"
     * — la richiesta della posizione e la frase che dice perché — vive in
     * lang/it/map.php, perché è la stessa in ogni pagina che la offre (§11.7).
     */
    'distance' => [
        'label' => 'Distanza',
        'radius' => 'Entro :km km',
    ],

    'features' => [
        'label' => 'Caratteristiche',
        'outdoor' => "All'aperto",
        'accessible' => 'Accessibile',
        'family' => 'Adatto alle famiglie',
    ],

    /*
     * Il filtro per voce di accessibilità (§11.3). Esiste perché
     * `venues.accessibility` è strutturato: su testo libero non sarebbe
     * possibile, ed è la ragione per cui è stato strutturato.
     */
    'accessibility' => [
        'label' => 'Accessibilità',
        'any' => 'Qualsiasi',
    ],

    'sort' => [
        'label' => 'Ordina per',
        'relevance' => 'Rilevanza',
        'time' => 'Orario',
        'distance' => 'Distanza',
    ],

];
