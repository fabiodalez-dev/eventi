<?php

declare(strict_types=1);

/*
 * Le pagine redazionali di §11.1 (`/pagine/{slug}`). Qui c'è la cornice: i
 * testi veri stanno nel database, perché una correzione a un'informativa
 * privacy non può aspettare un rilascio.
 */
return [

    'updated_at' => 'Ultimo aggiornamento: :date',

    /* Le pagine legali stanno fuori dall'indice? No: devono essere trovabili.
       Ma non sono contenuto che compete, quindi non hanno un titolo di lista
       diverso dal proprio. */
    'breadcrumb' => 'Informazioni',

];
