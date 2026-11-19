<?php

declare(strict_types=1);

/*
 * Le parole comuni dei tre pannelli (redazione, locale, organizzatore): quelle
 * che ogni pagina usa allo stesso modo, così la stessa cosa si chiama nello
 * stesso modo ovunque. Le parole di una pagina sola stanno nel file del suo
 * pacchetto (`pannelli-<chiave>.php`).
 */
return [

    'more' => 'Altro',
    'more_actions' => 'Altre azioni',
    'open' => 'Apri',
    'view_on_site' => 'Vedi sul sito',
    'copy' => 'Copia',
    'save' => 'Salva',
    'in_short' => 'In breve',
    'how_counted' => 'Come si contano questi numeri',

    'select' => [
        'event' => 'Cerca un evento',
        'event_label' => 'Evento',
        'upcoming_dates' => 'Prossime date',
        'past_dates' => 'Date passate',
        'all_events' => 'Tutti gli eventi',
        'upcoming' => 'Prossimi',
        'past' => 'Passati',
        'more_dates' => '{1} + un\'altra data|[2,*] + altre :count date',
        'no_dates' => 'Senza date',
        'no_results' => 'Non ho trovato eventi con queste parole.',
        'searching' => 'Cerco…',
        'type_to_search' => 'Scrivi il titolo dell\'evento o il nome del locale.',
        'occurrence' => 'Data',
        'occurrence_placeholder' => 'Scegli una data',
        'poster_alt' => 'Locandina di :title',
    ],

    'period' => [
        '7' => '7 giorni',
        '30' => '30 giorni',
        '90' => '90 giorni',
        '365' => 'Un anno',
        'all' => 'Da sempre',
        'custom' => 'Scegli le date',
    ],

    /* Le date scritte da `PanelDate`. I formati (giorno, mese, ora) li dà la
       lingua; qui ci sono solo le parole intorno. */
    'date' => [
        'empty' => '—',
        'now' => 'adesso',
        'minutes_ago' => '{1} un minuto fa|[2,*] :count minuti fa',
        'hours_ago' => '{1} un\'ora fa|[2,*] :count ore fa',
        'yesterday' => 'ieri alle :time',
        'days_ago' => ':count giorni fa',
        'from' => 'dal :date',
        'from_elided' => 'dall\':date',
        'until' => 'al :date',
        'until_elided' => 'all\':date',
        'from_start' => 'dall\'inizio',
    ],

    'map' => [
        'saved' => 'Punto salvato:',
        'saved_no_address' => 'Punto salvato sulla mappa.',
        'missing' => 'Non hai ancora messo il punto: tocca la mappa dove si trova il posto.',
    ],

    'stats' => [
        'no_data' => 'Ancora nessun dato',
    ],

];
