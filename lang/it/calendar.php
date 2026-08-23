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
        'more' => 'Mostra gli altri',
        'less' => 'Mostra meno',
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
