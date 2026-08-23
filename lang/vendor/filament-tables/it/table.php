<?php

declare(strict_types=1);

/* Voci che il pacchetto Filament non traduce ancora in italiano (file parziale). */
return [

    'column_manager' => [
        'actions' => [
            'reorder' => ['label' => 'Riordina la colonna'],
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

    'result_count' => '{0} Nessun risultato|{1} :count risultato|[2,*] :count risultati',

];
