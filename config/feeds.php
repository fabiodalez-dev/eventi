<?php

declare(strict_types=1);

/*
 * Feed e widget (§11.10). Non sono un accessorio: sono il modo in cui il
 * catalogo esce da qui e finisce nel telefono e nel sito di qualcun altro,
 * e ogni copia riporta indietro chi la legge.
 */
return [

    /*
     * Quanto in là guardano i feed. Tre mesi: oltre, un calendario
     * sottoscritto si riempirebbe di date che cambieranno ancora.
     */
    'days_ahead' => 90,

    /*
     * Tetto di voci per singolo feed. Un file `.ics` da diecimila righe non
     * lo apre nessun telefono.
     */
    'max_items' => 200,

    /*
     * Voci del feed RSS: un lettore mostra le ultime, non l'archivio.
     */
    'rss_items' => 50,

];
