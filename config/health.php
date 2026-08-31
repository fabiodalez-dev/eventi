<?php

declare(strict_types=1);

use App\Support\OpsAlerts;
use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Spatie\Health\Notifications\CheckFailedNotification;
use Spatie\Health\Notifications\Notifiable;
use Spatie\Health\ResultStores\EloquentHealthResultStore;

/*
 * I controlli di stato di §16. Quali siano si dichiara in
 * `App\Providers\OperationsServiceProvider`, perché sono oggetti con opzioni e
 * non righe di configurazione; qui stanno il magazzino dei risultati, chi
 * viene avvisato e la chiave che protegge l'endpoint.
 *
 * L'endpoint è `/stato` (`routes/web.php`) e **non è pubblico**: risponde solo
 * a chi porta `OPS_HEALTH_TOKEN`. Senza chiave configurata non risponde a
 * nessuno — un elenco di ciò che nel sistema sta cedendo è esattamente la
 * pagina che non va lasciata aperta.
 */
return [

    /*
     * I risultati si salvano nel database e l'endpoint li rilegge da lì: un
     * monitor esterno che interroga ogni minuto non deve rieseguire ogni volta
     * un conteggio di byte sul disco e una scrittura di prova sulla cache.
     * A rieseguirli è `health:check`, dallo scheduler.
     */
    'result_stores' => [
        EloquentHealthResultStore::class => [
            'connection' => env('HEALTH_DB_CONNECTION', env('DB_CONNECTION')),
            'model' => HealthCheckResultHistoryItem::class,
            'keep_history_for_days' => 5,
        ],
    ],

    'notifications' => [
        'enabled' => env('HEALTH_NOTIFICATIONS_ENABLED', true),

        'notifications' => [
            CheckFailedNotification::class => ['mail'],
        ],

        'notifiable' => Notifiable::class,

        /*
         * Un'ora fra un avviso e il successivo. I controlli girano ogni cinque
         * minuti: senza freno, un database irraggiungibile per una notte
         * produrrebbe centoventi messaggi identici, e il centoventunesimo —
         * quello di un guasto diverso — arriverebbe in fondo a una casella che
         * nessuno apre più.
         */
        'throttle_notifications_for_minutes' => 60,
        'throttle_notifications_key' => 'health:latestNotificationSentAt:',

        /*
         * Anche i gialli avvisano. `false` di proposito: una sola sorgente di
         * import caduta è un giallo, ed è il momento in cui costa poco
         * rimediare — aspettare che diventino tutte rosse significa accorgersi
         * quando il danno è fatto.
         */
        'only_on_failure' => false,

        'mail' => [
            /*
             * Lo stesso indirizzo dei backup: un solo posto dove arrivano gli
             * allarmi di questo sistema. Vuoto, non parte nulla — è ciò che
             * tiene silenzioso lo sviluppo senza spegnere alcun interruttore.
             */
            'to' => OpsAlerts::recipients(env('OPS_ALERT_EMAIL')),

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Example'),
            ],
        ],

        'slack' => [
            'webhook_url' => env('HEALTH_SLACK_WEBHOOK_URL', ''),
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],
    ],

    /*
     * L'endpoint di Oh Dear resta spento: non fa parte di questo stack, e la
     * rotta che il pacchetto registrerebbe non avrebbe la protezione che
     * `routes/web.php` mette davanti a `/stato`.
     */
    'oh_dear_endpoint' => [
        'enabled' => false,

        /*
         * `false` non riguarda solo Oh Dear: è la chiave che i controller di
         * `/stato` leggono per decidere se **rieseguire** i controlli a ogni
         * richiesta. Lasciata a `true`, un monitor che interroga ogni minuto
         * farebbe girare sette controlli al minuto, per sempre, e un attacco
         * di richieste su un indirizzo di sola lettura diventerebbe carico
         * vero. Chi vuole l'esito fresco lo chiede con `?fresh`.
         */
        'always_send_fresh_results' => false,
        'secret' => env('OH_DEAR_HEALTH_CHECK_SECRET'),
        'url' => '/oh-dear-health-check-results',
    ],

    'horizon' => [
        'heartbeat_url' => env('HORIZON_HEARTBEAT_URL'),
    ],

    /*
     * Indirizzo che il controllo dello scheduler contatta quando lo scheduler
     * risulta vivo (un «dead man's switch» esterno: se il ping smette di
     * arrivare, avvisa il servizio remoto). Vuoto, non si contatta nessuno:
     * §16 vuole il minimo di trasferimenti verso terzi.
     */
    'schedule' => [
        'heartbeat_url' => env('SCHEDULE_HEARTBEAT_URL'),
    ],

    'theme' => 'light',

    'silence_health_queue_job' => true,

    /*
     * Uno stato degradato deve **vedersi come tale** anche a chi guarda solo il
     * codice HTTP: 503 è ciò che un monitor di uptime capisce senza leggere il
     * corpo della risposta. Il valore predefinito del pacchetto è 200, cioè un
     * guasto che risponde «va tutto bene».
     */
    'json_results_failure_status' => 503,

    'secret_token' => env('OPS_HEALTH_TOKEN'),

];
