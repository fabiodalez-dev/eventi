<?php

declare(strict_types=1);

return [

    'occurrences_generate' => [
        'description' => 'Materializza le occorrenze future degli eventi ricorrenti.',
        'option_recurrence' => 'Genera solo la ricorrenza con questo id',
        'option_months' => 'Mesi di orizzonte da materializzare',
        'empty' => 'Nessuna ricorrenza da estendere.',
        'done' => 'Ricorrenze elaborate: :recurrences. Occorrenze create: :created.',
    ],

    'notifications_send' => [
        'description' => 'Invia le notifiche programmate arrivate a scadenza.',
        'option_limit' => 'Quante righe elaborare al massimo in questa esecuzione',
        'empty' => 'Nessuna notifica da inviare.',
        'done' => 'Prese in carico: :claimed. Inviate: :sent. Saltate: :skipped. Rimandate: :deferred. Non riuscite: :failed.',
    ],

    'notifications_plan' => [
        'description' => 'Programma i riepiloghi e purga l\'archivio degli invii.',
        'done' => 'Riepiloghi settimanali: :venue_digest. Giornalieri: :daily_digest. Weekend: :weekend. Locali inattivi: :inactive. Righe di archivio rimosse: :purged.',
    ],

];
