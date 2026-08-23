<?php

declare(strict_types=1);

/*
 * Voci che il pacchetto Filament non traduce ancora in italiano.
 *
 * Il file è **parziale** di proposito: Laravel fonde queste chiavi con quelle
 * del pacchetto (`array_replace_recursive`), quindi qui stanno solo le
 * mancanti. Ricopiarlo tutto significherebbe congelare a oggi anche le
 * traduzioni che il pacchetto già ha, e perderne i miglioramenti.
 *
 * Sono quasi tutte etichette per i lettori di schermo: invisibili, e proprio
 * per questo il posto dove una parola inglese passa inosservata per mesi.
 */
return [

    'skip_to_content' => [
        'label' => 'Vai al contenuto',
    ],

    'actions' => [
        'open_database_notifications' => [
            'label_with_unread_count' => '{1} Notifiche, :count non letta|[2,*] Notifiche, :count non lette',
        ],
        'theme_switcher' => [
            'label' => 'Tema',
        ],
    ],

    'navigation' => [
        'label' => 'Menu di navigazione',
    ],

    'topbar' => [
        'label' => 'Barra superiore',
    ],

];
