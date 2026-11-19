<?php

declare(strict_types=1);

/*
 * Le parole delle pagine di statistiche dei tre pannelli: le frasi sotto i
 * numeri, la riga dell'evento in testa, «Guarda un altro evento».
 */
return [
    'summary' => [
        'heading' => 'In breve',
        'label' => 'I numeri principali del periodo',
    ],

    'hints' => [
        'views' => 'Quante volte qualcuno ha aperto la pagina di un evento.',
        'interactions' => 'Quante volte qualcuno ha toccato un pulsante: indicazioni, telefono, sito, biglietti.',
        'short_shares' => 'Quante volte un evento è stato condiviso con il link breve.',
        'short_clicks' => 'Quante persone sono arrivate aprendo un link condiviso.',
        'saves' => 'Quante persone hanno salvato un evento per ritrovarlo.',
        'bookings_confirmed' => 'Posti prenotati e confermati.',
        'event_views' => 'Visite alle pagine dei tuoi eventi.',
        'profile_views' => 'Visite alla pagina del locale o dell\'organizzatore.',
        'clicks' => 'Pulsanti toccati sulle pagine dei tuoi eventi.',
        'followers' => 'Le persone che ti seguono e ricevono le novità.',
        'occurrence_views' => 'Visite alla pagina di questa data.',
        'occurrence_clicks' => 'Pulsanti toccati per questa data.',
        'occurrence_saves' => 'Persone che hanno salvato questa data.',
    ],

    'subject' => [
        'related_venue' => 'Statistiche del locale :name',
        'related_organizer' => 'Statistiche dell’organizzatore :name',
        'event_heading' => 'Stai guardando',
        'other_event' => 'Guarda un altro evento',
        'other_event_hint' => 'Scegli un evento: tengo lo stesso periodo e lo stesso canale.',
    ],

    'occurrence' => [
        'heading' => 'Statistiche di :date',
        'chart' => 'Giorno per giorno, ultimi 30 giorni',
        'table' => 'Vedi i numeri giorno per giorno',
    ],

    'labels' => [
        'statistics' => 'Statistiche',
        'clicks' => 'Pulsanti toccati',
        'followers' => 'Chi ti segue',
        'new_followers' => 'Nuovi da seguirti',
        'impressions' => 'Volte in cui è stato mostrato',
        'paid_clicks' => 'Aperture dalla sponsorizzazione',
        'ctr' => 'Su 100 che lo vedono, lo aprono',
    ],
];
