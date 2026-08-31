<?php

declare(strict_types=1);

/*
 * Il tracciamento degli errori di §16.
 *
 * **Senza DSN è spento e non fa niente.** Non è un accorgimento nostro: il
 * provider del pacchetto non registra alcun ascoltatore quando il DSN manca,
 * e le chiamate di cattura finiscono su un hub senza client, che le butta via.
 * È la ragione per cui sviluppo, test e integrazione continua non hanno bisogno
 * di alcun interruttore: non c'è nulla da spegnere.
 *
 * L'alternativa completamente aperta, e installabile in proprio, è GlitchTip:
 * parla lo stesso protocollo e si accende cambiando il solo DSN.
 */
return [

    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    /*
     * La revisione pubblicata, per attribuire un errore al rilascio che lo ha
     * introdotto. La riempie la pipeline con lo sha del commit.
     */
    'release' => env('SENTRY_RELEASE'),

    'environment' => env('SENTRY_ENVIRONMENT'),

    'org_id' => env('SENTRY_ORG_ID') === null ? null : (int) env('SENTRY_ORG_ID'),

    /*
     * Gli errori si mandano tutti: sono rari per definizione, e campionarli
     * significa non vedere proprio quello che è capitato una volta sola.
     */
    'sample_rate' => env('SENTRY_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_SAMPLE_RATE'),

    /*
     * Le tracce di prestazione, invece, sono una per richiesta: `null` le
     * spegne. Su una shared hosting il costo di misurare ogni richiesta si
     * paga sulla richiesta stessa, e §16 chiede il tracciamento degli errori,
     * non un profilatore acceso in produzione.
     */
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_TRACES_SAMPLE_RATE'),

    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_PROFILES_SAMPLE_RATE'),

    /*
     * §16, privacy: «raccogliere il minimo». Con questo acceso il pacchetto
     * allegherebbe a ogni errore indirizzo IP, cookie, corpo della richiesta e
     * identità di chi era collegato — cioè manderebbe dati personali a un
     * servizio terzo a ogni eccezione. Resta spento.
     */
    'send_default_pii' => false,

    /*
     * Le richieste di controllo non sono traffico: `/up` è il controllo di
     * Laravel, `/stato` il nostro (§16). Tracciarle riempirebbe le statistiche
     * con le interrogazioni del monitor di uptime, che sono una al minuto.
     */
    'ignore_transactions' => [
        '/up',
        '/stato',
        '/stato/completo',
    ],

    /*
     * Le briciole raccontano che cosa è successo **prima** dell'errore, ed è
     * ciò che rende leggibile una traccia. Restano fuori i parametri delle
     * interrogazioni SQL, che contengono i dati veri: il testo della query
     * basta a capire quale sia, il valore no (§16).
     */
    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,
    ],

    /*
     * Il tracciamento delle prestazioni resta comunque configurato: se un
     * giorno si accenderà `SENTRY_TRACES_SAMPLE_RATE`, queste sono le
     * misurazioni che si vogliono, senza i valori dei parametri SQL.
     */
    'tracing' => [
        'queue_job_transactions' => true,
        'queue_jobs' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'sql_origin' => true,
        'sql_origin_threshold_ms' => 100,
        'views' => true,
        'livewire' => true,
        'http_client_requests' => true,
        'cache' => false,
        'redis_commands' => false,
        'redis_origin' => false,
        'notifications' => true,
        'missing_routes' => false,
        'continue_after_response' => true,
        'default_integrations' => true,
    ],

];
