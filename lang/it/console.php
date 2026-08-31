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
        'skipped' => 'Sorgenti saltate perché l\'import della loro città è spento: :sources.',
        'source' => 'Sorgente :id (:url)',
        'failed' => 'Sorgente :id non riuscita: :reason',
        'done' => 'Sorgenti eseguite: :sources. Creati: :created. Aggiornati: :updated. Invariati: :unchanged. Esclusi: :excluded. Annullati: :cancelled. Errori: :errors.',
    ],

    'events_archive' => [
        'description' => 'Archivia gli eventi le cui date sono tutte passate.',
        'option_days' => 'Da quanti giorni devono essere passate tutte le date',
        'option_dry_run' => 'Conta soltanto: non archivia nulla',
        'empty' => 'Nessun evento con tutte le date passate da più di :days giorni.',
        'city' => ':city: :count eventi',
        'done' => 'Eventi archiviati: :count (tutte le date passate da più di :days giorni).',
        'would_archive' => 'Da archiviare: :count (tutte le date passate da più di :days giorni). Nulla è stato modificato.',
    ],

    'notifications_plan' => [
        'description' => 'Programma i riepiloghi e purga l\'archivio degli invii.',
        'done' => 'Riepiloghi settimanali: :venue_digest. Giornalieri: :daily_digest. Weekend: :weekend. Locali inattivi: :inactive. Righe di archivio rimosse: :purged.',
    ],

];
