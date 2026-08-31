<?php

declare(strict_types=1);

/*
 * I testi dei comandi di esercizio (§16): backup, stato, interruttori.
 */
return [

    'feature_set' => [
        'description' => 'Accende o spegne un interruttore di funzione.',
        'argument_feature' => 'Nome dell\'interruttore',
        'option_city' => 'Slug della città su cui agire, per gli interruttori che ne hanno una',
        'option_off' => 'Spegni invece di accendere',
        'unknown' => 'Interruttore sconosciuto: :feature. Disponibili: :available',
        'needs_city' => 'L\'interruttore :feature riguarda una città: indicarla con --city=<slug>.',
        'unwanted_city' => 'L\'interruttore :feature vale per tutto il sistema: --city non ha effetto.',
        'city_not_found' => 'Nessuna città con lo slug :slug.',
        'activated' => 'Acceso :feature per :scope.',
        'deactivated' => 'Spento :feature per :scope.',
        'global_scope' => 'tutto il sistema',
    ],

];
