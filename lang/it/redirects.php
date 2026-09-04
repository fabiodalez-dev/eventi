<?php

declare(strict_types=1);

return [
    'title' => 'Indirizzi cambiati',
    'lead' => 'Dove mandare chi arriva da un indirizzo che non esiste più. Le righe di una rinomina le scrive il sistema da solo: qui si aggiungono quelle che nessuno può indovinare — una sezione tolta, un indirizzo stampato su un volantino.',

    'fields' => [
        'city' => 'Città',
        'city_help' => 'Vuoto se vale per tutte. Serve solo quando lo stesso indirizzo esiste in più città con destinazioni diverse.',
        'city_any' => 'Tutte',
        'from' => 'Indirizzo vecchio',
        'from_help' => 'Senza il nome della città: «/eventi/vecchio-slug». Con un asterisco in fondo copre tutto quello che c\'è sotto.',
        'to' => 'Dove mandarlo',
        'to_help' => 'Sempre senza il nome della città. Il prefisso lo rimette il sito, uguale a com\'era nel link su cui si è cliccato.',
        'wildcard' => 'Copre tutto un ramo',
        'wildcard_help' => 'Un indirizzo per volta oppure tutto quello che sta sotto. La seconda serve quando cambia il nome di una città.',
        'status' => 'Tipo di spostamento',
        'hits' => 'Passaggi',
        'last_hit_at' => 'Ultimo passaggio',
        'never' => 'Mai',
    ],

    'status' => [
        'permanent' => 'Definitivo (301)',
        'temporary' => 'Temporaneo (302)',
    ],

    'actions' => [
        'add' => 'Aggiungi un indirizzo',
    ],

    'empty' => [
        'heading' => 'Nessun indirizzo cambiato',
        'description' => 'Comparirà una riga da sola ogni volta che una rinomina cambia l\'indirizzo di un evento, di un locale, di una categoria o di una città.',
    ],
];
