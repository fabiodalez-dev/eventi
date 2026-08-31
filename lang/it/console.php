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

    'import_run' => [
        'description' => 'Scarica e importa i calendari delle sorgenti attive.',
        'option_source' => 'Esegui solo la sorgente con questo id',
        'option_sync' => 'Esegui subito invece di accodare, e mostra il resoconto',
        'empty' => 'Nessuna sorgente da eseguire.',
        'queued' => 'Sorgenti accodate: :sources.',
        'source' => 'Sorgente :id (:url)',
        'failed' => 'Sorgente :id non riuscita: :reason',
        'done' => 'Sorgenti eseguite: :sources. Creati: :created. Aggiornati: :updated. Invariati: :unchanged. Esclusi: :excluded. Annullati: :cancelled. Errori: :errors.',
    ],

    'notifications_plan' => [
        'description' => 'Programma i riepiloghi e purga l\'archivio degli invii.',
        'done' => 'Riepiloghi settimanali: :venue_digest. Giornalieri: :daily_digest. Weekend: :weekend. Locali inattivi: :inactive. Righe di archivio rimosse: :purged.',
    ],

];
