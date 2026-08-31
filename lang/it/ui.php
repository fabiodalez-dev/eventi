<?php

declare(strict_types=1);

/*
 * Interfaccia comune: intestazione, navigazione, piè di pagina, stati.
 *
 * Il nome del prodotto non compare mai qui: arriva sempre da
 * config('app.name') e viene passato come :app dove serve (D10).
 */
return [

    'skip_to_content' => 'Vai al contenuto',
    'breadcrumb' => 'Percorso',

    'cta' => [
        'label' => 'Partecipa al progetto',
    ],

    'header' => [
        'home' => 'Torna alla pagina iniziale di :app',
        'city_label' => 'Città',
        'change_city' => 'Cambia città',
        'today_is' => 'Oggi è :date',
        'search_placeholder' => 'Cerca un evento, un locale, un artista',
        'search_label' => 'Cerca fra gli eventi di :city',
        'tagline' => 'Cosa fare stasera a :city',
    ],

    'nav' => [
        'label' => 'Navigazione principale',
        'home' => 'Home',
        'events' => 'Eventi',
        'today' => 'Oggi',
        'tonight' => 'Stasera',
        'tomorrow' => 'Domani',
        'weekend' => 'Weekend',
        'free' => 'Gratis',
        'map' => 'Mappa',
        'calendar' => 'Calendario',
        'venues' => 'Locali',
        'search' => 'Cerca',
        'saved' => 'Salvati',
        'account' => 'Il mio profilo',
    ],

    'footer' => [
        'label' => 'Piè di pagina',
        'about_title' => 'Il progetto',
        'about_body' => ':app raccoglie gli eventi di :city e provincia in un posto solo, aggiornati dai locali che li organizzano.',
        'discover_title' => 'Scopri',
        'venues_title' => 'Per i locali',
        /* Le voci di questo gruppo sono i titoli delle pagine pubblicate
           (tabella `pages`), non stringhe: la redazione le rinomina senza
           passare da qui. */
        'legal_title' => 'Informazioni',
        'submit_event' => 'Proponi un evento',
        'register_venue' => 'Registra il tuo locale',
        'venue_login' => 'Accedi al pannello del locale',
        'feeds_title' => 'Restare aggiornati',
        'calendar_feed' => 'Calendario iCal',
        'rss_feed' => 'Feed RSS',
        'newsletter' => 'Newsletter del weekend',
        'map_attribution' => 'Dati cartografici © :osm contributors, licenza :license',
        'osm' => 'OpenStreetMap',
        'odbl' => 'ODbL',
        'copyright' => '© :year :app',
    ],

    'pagination' => [
        'label' => 'Paginazione',
        'previous' => 'Precedente',
        'next' => 'Successiva',
        'page' => 'Pagina :page',
        'go_to_page' => 'Vai alla pagina :page',
        'current_page' => 'Pagina corrente, pagina :page',
        'showing' => 'Da :first a :last di :total risultati',
    ],

    'empty_state' => [
        'default_title' => 'Non c\'è niente da mostrare',
    ],

    'errors' => [
        '404_title' => 'Questa pagina non esiste',
        '404_body' => 'Il link potrebbe essere vecchio, oppure l\'evento è stato rimosso.',
        '500_title' => 'Qualcosa si è rotto da parte nostra',
        '500_body' => 'Riprova fra qualche minuto.',
        'back_home' => 'Torna alla home',
    ],

    'theme' => [
        'light' => 'Tema chiaro',
        'dark' => 'Tema scuro',
    ],

];
