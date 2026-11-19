<?php

declare(strict_types=1);

/*
 * Testi degli elenchi e della vista degli eventi nei tre pannelli
 * (redazione, locale, organizzatore).
 */
return [
    'list' => [
        'was' => 'Si è svolto :date',
        'published' => 'Pubblicato :when',
        'no_dates' => 'Nessuna data in calendario',
        'empty_heading' => 'Non hai ancora eventi',
        'empty_description' => 'Crea il primo con «Nuovo evento»: bastano titolo, data e locandina. Lo vedrai qui appena salvato.',
    ],
    'view' => [
        'doors' => 'apertura porte :time',
        'ends' => 'finisce alle :time',
        'ends_other_day' => 'finisce :date',
        'tickets' => ':date — gestisci i biglietti',
        'scheduled' => 'La pubblicazione è prevista per :date.',
    ],
];
