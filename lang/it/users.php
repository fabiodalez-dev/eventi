<?php

return [
    'description' => 'Qui trovi le persone e i loro accessi. I locali hanno una sezione separata. La città di riferimento è facoltativa: non viene dedotta dalle attività degli utenti.',
    'all' => 'Tutti',
    'admins' => 'Admin piattaforma',
    'staff' => 'Moderatori',
    'owners' => 'Amministratori locali',
    'editors' => 'Collaboratori',
    'users' => 'Utenti normali',
    'unlinked' => 'Senza locale',
    'venues' => 'Vai ai locali',
    'platform_roles' => 'Ruoli nella piattaforma',
    'roles_help' => 'I ruoli globali non assegnano un locale. Referenti e collaboratori si collegano dalla scheda del locale, nella sezione del team.',
    'memberships' => 'Locali e incarichi',
    'no_venues' => 'Nessun locale assegnato',
    'city' => 'Città di riferimento',
    'city_help' => 'Indica una città solo se conosciuta. Non è la residenza e non limita gli accessi.',
    'unknown_city' => 'Non indicata',
    'venue_cities' => 'Città dei locali gestiti',
    'missing_city' => 'Città di riferimento mancante',
    'venue' => 'Locale gestito',

    'navigation_group' => 'Persone',
    'navigation_badge' => 'Iscritti negli ultimi 7 giorni che non hanno ancora completato l\'iscrizione',
    'no_name' => 'Senza nome',
    'more_actions' => 'Altre azioni',
    'roles_short' => 'Ruoli',

    'registration' => [
        'registered_at' => 'Iscritto il',
        'state' => 'Stato',
        'filter' => 'Iscrizione',
        'tab' => 'In attesa di conferma',
        'states' => [
            'confirmed' => 'Confermato',
            'pending' => 'In attesa di conferma',
            'first_access' => 'Primo accesso da completare',
        ],
        'help' => [
            'confirmed' => 'Ha confermato l\'indirizzo email: l\'account è attivo.',
            'pending' => 'Si è iscritto ma non ha ancora aperto il collegamento di conferma arrivato per email. Rifacendo l\'accesso o la registrazione con la stessa password riceve un nuovo collegamento.',
            'first_access' => 'Ha confermato l\'indirizzo da un altro dispositivo: per entrare deve aprire il collegamento di accesso che riceve per email, poi la password funziona.',
        ],
    ],

    'widget' => [
        'heading' => 'Nuove iscrizioni',
        'description' => '{0} Nessuna iscrizione negli ultimi :days giorni|{1} Una iscrizione negli ultimi :days giorni|[2,*] :count iscrizioni negli ultimi :days giorni',
        'pending' => '{0} nessuna in attesa di conferma|{1} una in attesa di conferma|[2,*] :count in attesa di conferma',
        'all' => 'Tutti gli utenti',
        'show_pending' => 'Solo in attesa',
        'empty' => 'Ancora nessun iscritto',
        'empty_description' => 'Qui compaiono le persone appena si registrano dal sito o dall\'app.',
    ],
];
