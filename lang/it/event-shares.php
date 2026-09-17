<?php

declare(strict_types=1);

return [
    'title' => 'Analytics condivisioni',
    'description' => 'Link brevi degli eventi, distinti per canale e data. Il periodo selezionato si applica ai conteggi.',
    'event' => 'Evento',
    'channel' => 'Canale',
    'link' => 'Link breve',
    'shares' => 'Condivisioni avviate',
    'clicks' => 'Aperture del link',
    'occurrence' => 'Data :date',
    'empty' => 'Nessun link breve generato per questi eventi.',
    'notes' => 'Conteggi aggregati con consenso statistiche, senza IP o identificatori dei visitatori. I pulsanti WhatsApp, Telegram ed email misurano l’avvio, non la consegna del messaggio; Condividi misura il completamento o la copia. Le aperture includono visite ripetute, escludono anteprime e bot riconosciuti e non sono utenti unici. Un link può essere inoltrato su altri canali: l’attribuzione resta al canale che lo ha generato.',
    'channels' => ['native' => 'Condividi / copia', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'email' => 'Email'],
];
