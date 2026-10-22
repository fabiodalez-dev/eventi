<?php

declare(strict_types=1);

/*
 * Feed e widget (§11.10).
 */
return [

    'calendar' => [
        'title' => 'Calendario da sottoscrivere',
        'description' => ':name — aggiornato da :app.',
    ],

    'rss' => [
        'title' => 'Feed RSS',
        'description' => 'Gli eventi in programma a :city, aggiornati.',
    ],

    'public' => [
        'title' => 'Calendario pubblico (ICS)',
        'lead' => 'Un indirizzo fisso da aggiungere al calendario del telefono o al sito: le nuove date arrivano da sole, senza account.',
    ],

    'subscribe' => 'Sottoscrivi questa lista',
    'subscribe_lead' => 'Gli stessi filtri, nel calendario del telefono o in un lettore di feed: le nuove date arrivano da sole.',

];
