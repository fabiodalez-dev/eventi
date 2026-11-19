<?php

declare(strict_types=1);

/*
 * Il calendario mensile (§11.8).
 */
return [

    'title' => 'Calendario',
    'month_year' => ':month :year',

    'meta' => [
        'title' => 'Calendario di :month a :city',
        'description' => 'Tutti gli eventi di :month a :city e provincia, giorno per giorno.',
    ],

    'label' => 'Calendario di :month',
    'previous' => 'Mese precedente',
    'next' => 'Mese successivo',
    'current' => 'Torna a questo mese',
    'total' => ':count evento in :month|:count eventi in :month',

    'day' => [
        'link' => 'Eventi di :date',
        'count' => ':count evento|:count eventi',
        'more' => 'Mostra i titoli',
        'less' => 'Nascondi i titoli',
    ],

    /*
     * Il calendario del mese nella pagina iniziale. `mode` dice di chi è:
     * della città (ospite), della persona (segue i suoi interessi), della
     * città perché i suoi interessi questo mese non portano a niente
     * (`fallback`), o della città senza ciò che ha nascosto, perché non ha
     * ancora scelto interessi (`filtered`).
     */
    'home' => [
        'eyebrow' => [
            'city' => 'Il calendario della città',
            'personal' => 'Il tuo calendario',
            'fallback' => 'Il calendario della città',
            'filtered' => 'Il calendario della città',
        ],
        'total' => [
            'city' => ':count evento questo mese|:count eventi questo mese',
            'personal' => ':count evento per te questo mese|:count eventi per te questo mese',
        ],
        'hint' => [
            'fallback' => 'Questo mese non c\'è ancora niente che segue i tuoi interessi: ecco tutto quello che c\'è in città.',
            'filtered' => 'Scegli categorie e locali che ti interessano e qui vedrai solo i loro eventi.',
        ],
        'preferences' => 'Scegli i tuoi interessi',
        'open' => 'Apri il calendario',
        'next' => [
            'eyebrow' => [
                'personal' => 'Il prossimo per te',
                'city' => 'Il prossimo in città',
            ],
            'title' => [
                'soon' => 'Inizia tra poco',
                'next' => 'Il prossimo evento',
            ],
            'open' => 'Vedi l\'evento',
            'following' => 'A seguire',
        ],
    ],

    'weekdays' => [
        'mon' => 'lun',
        'tue' => 'mar',
        'wed' => 'mer',
        'thu' => 'gio',
        'fri' => 'ven',
        'sat' => 'sab',
        'sun' => 'dom',
    ],

    'weekdays_full' => [
        'mon' => 'lunedì',
        'tue' => 'martedì',
        'wed' => 'mercoledì',
        'thu' => 'giovedì',
        'fri' => 'venerdì',
        'sat' => 'sabato',
        'sun' => 'domenica',
    ],

    'empty' => [
        'title' => 'In questo mese non c\'è ancora niente in programma',
        'body' => 'I programmi arrivano spesso a ridosso della data: prova il mese seguente o guarda cosa c\'è adesso.',
    ],

];
