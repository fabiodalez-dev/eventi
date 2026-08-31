<?php

declare(strict_types=1);

/*
 * Il consenso di §16: «cookie banner con consenso preventivo, granulare,
 * rifiuto semplice quanto l'accettazione, log del consenso».
 *
 * Preventivo vuol dire che finché una scelta non è stata registrata **nulla di
 * facoltativo viene caricato**: `App\Support\Consent` risponde `false` a ogni
 * categoria che non sia quella necessaria, e la vista dell'analitica non emette
 * niente. Non c'è un percorso in cui lo script parte «in attesa» del consenso.
 */
return [

    /*
     * Il nome del cookie che porta la scelta. È l'unico cookie che questo
     * banner crea, ed è esso stesso tecnicamente necessario: senza, la scelta
     * andrebbe richiesta a ogni pagina.
     */
    'cookie' => 'consenso',

    /*
     * Per quanto vale una scelta. Sei mesi: il Garante indica sei mesi come
     * termine oltre il quale la richiesta può essere riproposta a chi ha
     * rifiutato, e tenere un'unica durata per accettazione e rifiuto è ciò che
     * rende le due strade davvero equivalenti — un «sì» che dura due anni e un
     * «no» che dura una settimana non sono la stessa scelta.
     */
    'lifetime_days' => 180,

    /*
     * La versione dell'informativa a cui la scelta si riferisce. Cambiandola
     * ogni scelta registrata prima torna a essere «non ancora espressa» e il
     * banner ricompare: è il solo modo di far valere davvero un cambiamento
     * delle finalità.
     */
    'version' => env('CONSENT_VERSION', '2026-08-31'),

    /*
     * Gli slug delle pagine legali a cui il banner rimanda. Sono in
     * configurazione e non scritti nella vista perché la stessa coppia serve al
     * seeder, al piè di pagina e al banner: tre copie divergono.
     */
    'policy_page' => 'privacy',
    'cookie_page' => 'cookie',

];
