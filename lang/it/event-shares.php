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
    'custom' => [
        'title' => 'Canali e codici QR',
        'lead' => 'Un canale con il nome che gli dai — volantino, radio, un partner — e il codice QR da stampare. Il QR porta allo stesso link breve, quindi le aperture finiscono nello stesso conteggio.',
        'label' => 'Nome del canale',
        'placeholder' => 'Volantino, radio, locandina…',
        'event' => 'Collegato a',
        'venue_target' => 'La scheda del locale',
        'create' => 'Crea il canale',
        'created' => 'Canale «:channel» creato.',
        'invalid' => 'Serve un nome di almeno due lettere.',
        'limit' => 'Hai già :count canali personalizzati: usa uno di quelli invece di aggiungerne un altro.',
        'qr' => 'Codice QR',
        'qr_alt' => 'Codice QR del canale :channel',
        'download' => 'Scarica il QR',
        'target' => 'Porta a',
        'empty' => 'Ancora nessun canale personalizzato.',
        'owner_only' => 'I canali li crea il referente del locale, come le altre modifiche alla scheda.',
    ],
    'channels' => ['native' => 'Condividi / copia', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'email' => 'Email'],
];
