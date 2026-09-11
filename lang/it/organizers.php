<?php

declare(strict_types=1);

/*
 * Gli organizzatori: chi mette in piedi le serate, che non sempre coincide con
 * il locale che le ospita.
 *
 * Le stringhe stavano scritte a mano dentro le viste e dentro il controller.
 * Non è una questione di purezza: un titolo di pagina scritto in un `new
 * PageMeta('Organizzatori', ...)` è invisibile a chi cerca cosa dice il sito, e
 * il giorno in cui `lang/en` smette di essere vuoto resta indietro in silenzio.
 */
return [

    'title' => 'Organizzatori',
    'lead' => 'Associazioni, collettivi e promotori: tutti i loro eventi, anche in locali diversi.',

    'search_label' => 'Cerca un organizzatore',
    'empty' => 'Nessun organizzatore trovato.',

    'eyebrow' => 'Organizzatore',
    'website' => 'Sito dell’organizzatore',

    'archive_label' => 'Date dell’organizzatore',
    'upcoming' => 'In programma',
    'archive' => 'Archivio',
    'empty_upcoming' => 'Nessuna data in programma.',
    'empty_archive' => 'Nessuna data in archivio.',

    'from_venues' => 'Cerchi chi organizza? Scopri gli organizzatori e tutti i loro eventi.',

    'meta' => [
        'index' => 'Associazioni, collettivi e promotori che organizzano eventi a :city.',
        'search' => 'Organizzatori che corrispondono a «:query» a :city.',
    ],

];
