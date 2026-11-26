<?php

declare(strict_types=1);

/*
 * I messaggi dell'API del catalogo della redazione (R11) che il pannello non
 * ha già in `admin.php`: lì gli avvisi di salvataggio sono quelli generici
 * di Filament, e una voce ancora usata finisce in un errore del database.
 */
return [
    'created' => 'Creato.',
    'saved' => 'Modifiche salvate.',
    'deleted' => 'Eliminato.',
    'in_use' => 'Non si può eliminare: è ancora usato da eventi, locali o altre voci. Toglilo da lì prima, oppure spegnilo.',
    'page_changed' => 'Nel frattempo qualcuno ha modificato questa pagina: ricaricala e rifai la correzione.',
    'merge_absorbed' => 'tag doppione',
];
