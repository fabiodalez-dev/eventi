<?php

declare(strict_types=1);

/*
 * §12.2: sitemap a indice, robots, canonical, Open Graph, metadata X/Twitter,
 * `hreflang` predisposto.
 */
return [

    'sitemap' => [
        /*
         * Quanti indirizzi entrano in una singola mappa prima che se ne apra
         * un'altra. Il protocollo ne ammette 50.000 e 50 MB; 2.000 è un numero
         * prudente che tiene ogni file sotto il megabyte e ne rende leggibile
         * uno per volta quando qualcosa non torna.
         */
        'chunk' => 2000,

        /*
         * Quanti giorni futuri hanno una pagina propria nella mappa
         * (`/eventi/{yyyy-mm-dd}`). Oltre l'orizzonte in cui i locali
         * annunciano davvero, sarebbero pagine vuote offerte all'indice.
         */
        'days_ahead' => 60,

        /*
         * Per quanto la mappa resta valida in cache. Cambia quando cambia il
         * programma, cioè alla pubblicazione di un evento: la chiave porta lo
         * stesso numero di versione dei conteggi del calendario.
         */
        'ttl_minutes' => 60,
    ],

    'robots' => [
        /*
         * Percorsi che non hanno senso in un indice: pannelli, moduli con
         * effetto, rappresentazioni alternative. Non sono un segreto — chi
         * vuole ci arriva lo stesso — sono spreco di passaggi di scansione.
         */
        'disallow' => [
            '/admin',
            '/gestione',
            '/impersona',
            '/widget',
            '/api/',
            '/docs/api',
            '/cerca',
        ],
    ],

    /*
     * `hreflang` **predisposto** (§12.2): oggi il sito parla una lingua sola e
     * l'unica riga emessa è `x-default`. Il giorno in cui `lang/en` smetterà di
     * essere vuoto basterà aggiungere la lingua qui, e ogni pagina dichiarerà
     * la propria alternativa senza che nessuna vista cambi.
     */
    'locales' => [
        'it' => null,
    ],

    /*
     * L'account X/Twitter del sito, se ne esiste uno: finisce in
     * `twitter:site`. Vuoto significa che la riga non viene emessa — meglio
     * nessuna dichiarazione che una che rimanda a un profilo inesistente.
     */
    'twitter_site' => env('SEO_TWITTER_SITE', ''),

    /*
     * L'organizzazione che pubblica, per il nodo JSON-LD `Organization`
     * (§12.2). Il nome è quello del prodotto; gli indirizzi social si
     * dichiarano solo se ci sono.
     */
    'organization' => [
        'legal_name' => env('SEO_ORGANIZATION', ''),
        'email' => env('SEO_ORGANIZATION_EMAIL', ''),
        'same_as' => array_values(array_filter(explode(',', (string) env('SEO_ORGANIZATION_SOCIALS', '')))),
    ],

];
