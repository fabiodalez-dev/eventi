<?php

declare(strict_types=1);

/*
 * I messaggi dei controlli di stato scritti qui (§16). Quelli dei controlli che
 * arrivano dai pacchetti sono già tradotti dai pacchetti stessi.
 */
return [

    /* Le etichette dei controlli, quelle che compaiono nell'email di allarme e
       nella risposta di `/stato/completo`. Senza, il pacchetto le deduce dal
       nome della classe e restano in inglese. */
    'labels' => [
        'calendar_coverage' => 'Copertura del calendario',
        'backup_freshness' => 'Backup recente e intero',
        'media_weight' => 'Peso delle locandine',
        'production_secrets' => 'Chiavi di produzione',
        'database' => 'Database',
        'cache' => 'Cache',
        'disk' => 'Spazio su disco',
        'queue' => 'Coda dei lavori',
        'schedule' => 'Esecuzione dello scheduler',
        'import_sources' => 'Sorgenti di import',
        'scheduled_tasks' => 'Comandi schedulati',
    ],

    'import_sources' => [
        'none' => 'Nessuna sorgente di import attiva: la città si popola a mano.',
        'summary_none' => 'Nessuna sorgente',
        'ok' => 'Tutte le :active sorgenti attive hanno letto senza errori.',
        'some_failing' => ':failed sorgenti di import su :active sono in errore.',
        'all_failing' => 'Tutte le :active sorgenti di import sono in errore: non entra più alcun evento dai calendari.',
        'summary' => ':failed su :active in errore',
    ],

    'scheduled_tasks' => [
        'none' => 'Nessun comando schedulato risulta sorvegliato: eseguire schedule-monitor:sync.',
        'summary_none' => 'Registro vuoto',
        'unmonitored' => 'Comandi schedulati fuori sorveglianza (eseguire schedule-monitor:sync): :tasks',
        'summary_unmonitored' => ':count fuori sorveglianza',
        'ok' => 'Tutti i :count comandi schedulati girano nei tempi previsti.',
        'summary_ok' => ':count nei tempi',
        'failed' => 'Comandi schedulati non riusciti all\'ultima esecuzione: :tasks',
        'summary_failed' => ':count non riusciti',
        'late' => 'Comandi schedulati che hanno smesso di girare: :tasks',
        'summary_late' => ':count in ritardo',
    ],

];
