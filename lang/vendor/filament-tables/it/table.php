<?php

declare(strict_types=1);

/* Voci che il pacchetto Filament non traduce ancora in italiano (file parziale). */
return [

    'column_manager' => [
        'actions' => [
            'reorder' => ['label' => 'Riordina la colonna'],
            'reset' => ['label' => 'Rimetti come prima'],
        ],
    ],

    /* Lo stato vuoto di serie: mai «Nessun risultato» (vedi `FilamentDefaultsServiceProvider`). */
    'empty' => [
        'heading' => 'Qui non c\'è ancora niente',
        'description' => 'Se hai messo un filtro o una ricerca, prova a toglierli.',
    ],

    'filters' => [
        'actions' => [
            'reset' => ['label' => 'Togli i filtri'],
            'remove_all' => [
                'label' => 'Togli tutti i filtri',
                'tooltip' => 'Togli tutti i filtri',
            ],
            'remove' => ['label' => 'Togli il filtro'],
        ],
    ],

    'columns' => [
        'icon' => [
            'boolean' => [
                'true' => 'Sì',
                'false' => 'No',
            ],
        ],
    ],

    'actions' => [
        'reorder_record' => ['label' => 'Riordina la riga :key'],
        'toggle_record_content' => ['label' => 'Apri o chiudi la riga :key'],
    ],

    'loading' => 'Caricamento…',

    'result_count' => '{0} Niente da mostrare|{1} :count risultato|[2,*] :count risultati',

];
