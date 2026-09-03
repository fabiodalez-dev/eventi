<?php

declare(strict_types=1);

/*
 * Le stringhe di `guava/calendar` in italiano.
 *
 * Il pacchetto non porta l'italiano, e senza questo file il titolo del widget
 * compariva a schermo come `guava-calendar::translations.heading` — la chiave
 * al posto del testo. È il modo in cui una traduzione mancante si manifesta in
 * Laravel: non un errore, la chiave stampata così com'è, che passa inosservata
 * a chi scrive e salta agli occhi a chi usa.
 */
return [
    'heading' => 'Calendario',
];
