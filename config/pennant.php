<?php

declare(strict_types=1);

/*
 * Gli interruttori di funzione. Quali esistano e che cosa accendano lo dice
 * `App\Support\Features`; qui stanno il magazzino dei valori risolti e il
 * valore predefinito di ciascuno.
 */
return [

    /*
     * Il magazzino è il database e non la memoria: un interruttore spento deve
     * restare spento anche per il worker della coda e per il comando lanciato
     * dal cron, che sono processi diversi da quello che l'ha spento. Con lo
     * store `array` si spegnerebbe una funzione solo per la richiesta in corso.
     */
    'default' => env('PENNANT_STORE', 'database'),

    'stores' => [

        'array' => [
            'driver' => 'array',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'features',
        ],

    ],

    /*
     * Il valore di un interruttore per un ambito su cui **nessuno ha ancora
     * deciso nulla**: una città appena creata, un sistema appena installato.
     * Dal primo `feature:set` in poi comanda la riga in `features`, e questi
     * valori non vengono più consultati per quell'ambito.
     *
     * Entrambi nascono accesi perché è il comportamento che il sistema aveva
     * prima che gli interruttori esistessero: una città nuova importa i propri
     * calendari, e chi dà il consenso riceve la newsletter. Un interruttore
     * introdotto spento cambia il prodotto di nascosto.
     */
    'defaults' => [

        /*
         * L'import dei calendari di una città (§14.2).
         *
         *     php artisan feature:set city-import --city=padova --off
         */
        'city-import' => env('FEATURE_CITY_IMPORT', true),

        /*
         * La newsletter del weekend (§15.9): consenso richiesto nei moduli e
         * riepilogo del giovedì messo in coda.
         *
         *     php artisan feature:set newsletter --off
         */
        'newsletter' => env('FEATURE_NEWSLETTER', true),

    ],

];
