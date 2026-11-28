<?php

declare(strict_types=1);

/*
 * I messaggi dell'API «Configurazione tecnica» della redazione nell'app
 * (R13). Le altre frasi vengono dai file di lingua delle pagine del
 * pannello (`notification_texts`, `mail_settings`, `cookies`, `redirects`,
 * `cache_settings`).
 */
return [
    'texts' => [
        'value' => 'testo',
        'changed' => 'Questo testo è stato cambiato nel frattempo: riaprilo per vedere la versione attuale.',
    ],
    'newsletter' => [
        'unknown' => 'Variabili non riconosciute: :tokens',
        'saved' => 'Impostazioni newsletter salvate',
    ],
    'cookies' => [
        'created' => 'Cookie aggiunto.',
        'reordered' => 'Ordine salvato.',
    ],
    'redirects' => [
        'created' => 'Indirizzo aggiunto.',
    ],
];
