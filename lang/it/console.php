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

    'community_rehash' => [
        'description' => 'Ricalcola le impronte dei numeri WhatsApp con la chiave nuova dopo una rotazione di WHATSAPP_PHONE_HASH_KEY.',
        'option_dry_run' => 'Classifica e riporta soltanto: non scrive nulla',
        'option_release_orphans' => 'Elimina le impronte che non si possono ricalcolare (account sospesi e cancellati): quei numeri tornano utilizzabili',
        'missing_new_key' => 'WHATSAPP_PHONE_HASH_KEY non è configurata: scrivi la chiave nuova in .env e lancia config:cache.',
        'missing_old_key' => 'Manca la chiave vecchia: leggila senza eco con read -rs OLDKEY e passala solo nell\'ambiente del comando, come :variable="$OLDKEY" davanti a php artisan (procedura in docs/RUNBOOK.md).',
        'same_keys' => 'La chiave vecchia e quella nuova coincidono: non c\'è nulla da ricalcolare.',
        'busy' => 'Un\'altra operazione sulla verifica WhatsApp tiene il lock: riprova fra qualche secondo.',
        'class' => 'Classe',
        'users' => 'Utenti',
        'challenges' => 'Richieste',
        'classes' => [
            'already' => 'già con la chiave nuova',
            'rehash' => 'da ricalcolare',
            'mismatch' => 'non riconosciute',
            'orphan' => 'orfane (sospesi cancellati)',
        ],
        'mismatch' => 'Alcune impronte non corrispondono né alla chiave nuova né alla vecchia: la chiave vecchia è probabilmente sbagliata.',
        'collision' => 'Con la chiave nuova più account avrebbero la stessa impronta.',
        'orphans' => 'Ci sono :count impronte orfane, di account sospesi e cancellati senza più il numero: non si possono ricalcolare. Rilancia con --release-orphans per eliminarle, sapendo che quei numeri tornano utilizzabili.',
        'users_ids' => 'Utenti: :ids',
        'challenges_ids' => 'Richieste: :ids',
        'nothing_written' => 'Nulla è stato scritto.',
        'would_release' => 'Con --release-orphans verrebbero eliminate :count impronte orfane: quei numeri tornerebbero utilizzabili.',
        'released' => 'Impronte orfane eliminate: :count. Quei numeri di account sospesi e cancellati sono di nuovo utilizzabili.',
        'dry_run' => 'Prova: da ricalcolare :users utenti e :challenges richieste. Nulla è stato scritto.',
        'done' => 'Impronte ricalcolate: :users utenti e :challenges richieste. La chiave vecchia non serve più.',
        'nothing_to_do' => 'Tutte le impronte sono già con la chiave nuova: nulla da scrivere.',
    ],

    'community_prune_fingerprints' => [
        'description' => 'Elimina le impronte dei numeri trattenute per gli account sospesi e cancellati oltre il periodo di conservazione.',
        'option_dry_run' => 'Conta soltanto: non elimina nulla',
        'empty' => 'Nessuna impronta con una sospensione più vecchia di :months mesi.',
        'would_remove' => 'Da eliminare: :count impronte (sospensione più vecchia di :months mesi). Nulla è stato modificato.',
        'done' => 'Impronte eliminate: :count (sospensione più vecchia di :months mesi).',
    ],

];
