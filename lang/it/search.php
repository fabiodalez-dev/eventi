<?php

declare(strict_types=1);

/*
 * La ricerca del sito (`/cerca`).
 */
return [

    'title' => 'Cerca',
    'results_for' => 'Risultati per ":query"',
    'placeholder' => 'Un evento, un locale, un artista',
    'label' => 'Che cosa cerchi',
    'submit' => 'Cerca',
    'live' => [
        'loading' => 'Ricerca in corso…',
        'error' => 'I suggerimenti non sono disponibili. Premi Cerca per riprovare.',
        'all' => 'Vedi tutti i risultati',
        'label' => 'Risultati della ricerca in diretta',
    ],

    'meta' => [
        'title' => 'Cerca fra gli eventi di :city',
        'description' => 'Cerca un evento, un locale o un tag fra ciò che succede a :city e provincia.',
        'results_description' => '{0} Nessun risultato per ":query" a :city.|{1} Un risultato per ":query" a :city.|[2,*] :count risultati per ":query" a :city.',
    ],

    'groups' => [
        'events' => 'Eventi',
        'venues' => 'Locali',
        'tags' => 'Tag',
    ],

    'all_events' => 'Tutti gli eventi che contengono ":query"',
    'all_venues' => 'Tutti i locali che contengono ":query"',

    'empty' => [
        'title' => 'Non ho trovato niente per ":query"',
        'body' => 'Può darsi che sia scritto in un altro modo, o che quell\'evento sia già passato. Da qui si riparte:',
        'prompt_title' => 'Che cosa stai cercando?',
        'prompt_body' => 'Scrivi il nome di un evento, di un locale o di un artista. Oppure parti da qui:',
        'categories' => 'Categorie con eventi in programma',
        'tags' => 'Tag più usati',
        'windows' => 'Oppure guarda subito',
    ],

];
