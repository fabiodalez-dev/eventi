<?php

declare(strict_types=1);

/*
 * Ricerca del sito (§11.1, rotta `/cerca`).
 *
 * Il motore è **il database stesso** (D5): sulla shared hosting non gira alcun
 * demone Meilisearch, e un indice esterno per un catalogo di poche migliaia di
 * righe sarebbe un servizio in più da tenere vivo. Il driver `database` non
 * mantiene alcun indice separato: traduce la ricerca in una query sulle
 * colonne dichiarate da `toSearchableArray()` di ciascun modello.
 *
 * Le colonne lunghe (le descrizioni) passano per `MATCH … AGAINST` grazie
 * all'attributo `SearchUsingFullText`, le altre per un confronto `LIKE` che
 * trova anche i pezzi di parola: "concer" deve portare a "concerto", cosa che
 * l'indice full-text da solo non fa.
 */
return [

    'driver' => env('SCOUT_DRIVER', 'database'),

    'prefix' => env('SCOUT_PREFIX', ''),

    'queue' => env('SCOUT_QUEUE', false),

    'after_commit' => false,

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    /*
     * Un contenuto cestinato non si cerca: la ricerca pubblica mostra ciò che
     * è pubblicato, e il cestino è un'altra cosa.
     */
    'soft_delete' => false,

    'identify' => false,

];
