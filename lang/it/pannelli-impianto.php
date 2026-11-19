<?php

declare(strict_types=1);

/*
 * L'impianto comune dei tre pannelli: i gruppi del menu laterale, il menu
 * della persona, le parole di tabelle e filtri. Il nome di ogni voce del menu
 * non sta qui: lo dà la pagina (`getNavigationLabel()`), così menu, barra in
 * alto e titolo dicono la stessa parola.
 */
return [

    'groups' => [
        'events' => 'Eventi',
        'places_people' => 'Locali e persone',
        'moderation' => 'Community e moderazione',
        'promotion' => 'Promozione',
        'statistics' => 'Statistiche',
        'catalog' => 'Catalogo',
        'system' => 'Sistema',
        'audience' => 'Cosa dice la gente',
        'venue' => 'Locale e persone',
        'organizer' => 'Organizzazione',
    ],

    'tickets' => 'Biglietti e partecipanti',

    'user_menu' => [
        'account' => 'Area personale',
        'appearance' => 'Tema chiaro o scuro',
        'to_light' => 'Passa al tema chiaro',
        'to_dark' => 'Passa al tema scuro',
        'logout' => 'Esci',
        'signed_in_as' => 'Sei dentro come',
    ],

    'table' => [
        'filters' => 'Filtri',
        'columns' => 'Colonne',
        'sort_choose' => 'Scegli',
        'empty_heading' => 'Qui non c\'è ancora niente',
        'empty_description' => 'Se hai messo un filtro o una ricerca, prova a toglierli.',
    ],

];
