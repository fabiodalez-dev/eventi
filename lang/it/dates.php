<?php

declare(strict_types=1);

/*
 * Formattazione umana delle date, usata da App\Support\DateFormatter.
 *
 * I nomi di giorni e mesi non stanno qui: li fornisce il locale italiano di
 * Carbon. Qui stanno le parole di collegamento e le forme che l'italiano non
 * ricava da un formato ("stasera alle 21:30" non è un `strftime`).
 */
return [

    'today' => 'oggi',
    'tomorrow' => 'domani',
    'yesterday' => 'ieri',
    'tonight' => 'stasera',
    'now' => 'adesso',

    /* :date è già una delle voci qui sopra oppure "venerdì 5 settembre" */
    'day_at_time' => ':date alle :time',
    'tonight_at_time' => 'stasera alle :time',
    'all_day' => 'tutto il giorno',

    /* "venerdì 5 settembre" e, se l'anno non è quello corrente, con l'anno */
    'weekday_day_month' => ':weekday :day :month',
    'weekday_day_month_year' => ':weekday :day :month :year',
    'day_month' => ':day :month',
    'day_month_year' => ':day :month :year',

    'time_range' => ':start – :end',
    'from_time' => 'dalle :time',

    /* Conto alla rovescia: forma breve per i badge, estesa per il testo */
    'countdown_minutes' => ':count min',
    'countdown_hours' => ':count h',
    'countdown_hours_minutes' => ':hours h :minutes min',

    'in_minutes' => 'tra :count minuto|tra :count minuti',
    'in_hours' => 'tra :count ora|tra :count ore',
    'in_days' => 'tra :count giorno|tra :count giorni',

    /* Chiavi degli orari di apertura dei locali (D19): il JSON usa il giorno
       in inglese abbreviato, la scheda lo mostra in italiano. */
    'weekdays' => [
        'mon' => 'Lunedì',
        'tue' => 'Martedì',
        'wed' => 'Mercoledì',
        'thu' => 'Giovedì',
        'fri' => 'Venerdì',
        'sat' => 'Sabato',
        'sun' => 'Domenica',
    ],

    'formats' => [
        'time' => 'H:i',
        'day_number' => 'j',
    ],

];
