<?php

declare(strict_types=1);

/*
 * Le sponsorizzazioni, lato pubblico e lato pannello.
 *
 * L'etichetta è la parte che non si tocca: dev'essere una parola che chiunque
 * riconosce come «questo è a pagamento». «In collaborazione con», «in
 * partnership», «promosso da» sono formule che ammorbidiscono e per questo non
 * vanno bene — se dopo averla letta uno può ancora credere che sia una scelta
 * della redazione, non ha fatto il suo lavoro.
 */
return [

    'label' => 'Sponsorizzato',
    'by' => 'a cura di :advertiser',

    'admin' => [
        'title' => 'Sponsorizzazioni',
        'singular' => 'Sponsorizzazione',
        'navigation' => 'Sponsorizzazioni',

        'sections' => [
            'what' => 'Cosa e dove',
            'when' => 'Quando',
            'who' => 'Chi la paga',
            'money' => 'Amministrazione',
            'metrics' => 'Misure',
        ],

        'fields' => [
            'event' => 'Evento',
            'placement' => 'Collocazione',
            'status' => 'Stato',
            'starts_at' => 'Inizio',
            'ends_at' => 'Fine',
            'priority' => 'Priorità',
            'advertiser_name' => 'Committente',
            'advertiser_email' => 'Email del committente',
            'advertiser_url' => 'Sito del committente',
            'amount' => 'Importo',
            'currency' => 'Valuta',
            'invoice_reference' => 'Riferimento fattura',
            'notes' => 'Note interne',
            'impressions' => 'Visualizzazioni',
            'clicks' => 'Aperture',
            'click_rate' => 'Rapporto',
            'created_by' => 'Creata da',
            'running' => 'In corso',
        ],

        'help' => [
            'placement' => 'Ogni collocazione ne ammette un numero fisso: se ce ne sono di più, si alternano.',
            'priority' => 'A parità di finestra e collocazione, chi ha il numero più alto compare per primo. Le altre restano in rotazione.',
            'advertiser_name' => 'Compare al pubblico accanto alla scritta «Sponsorizzato»: è chi paga, e può non essere il locale.',
            'window' => 'Fuori da questa finestra la campagna non compare, qualunque sia lo stato.',
            'metrics' => 'Misurate dal browser: chi blocca gli script non viene contato, quindi sono una stima al ribasso.',
            'event_not_published' => 'Questo evento non è pubblicato: la campagna non comparirà finché non lo sarà.',
        ],

        'filters' => [
            'running' => 'Solo quelle in corso adesso',
            'placement' => 'Collocazione',
            'status' => 'Stato',
        ],

        'actions' => [
            'activate' => 'Attiva',
            'pause' => 'Sospendi',
            'activated' => 'Campagna attivata.',
            'paused' => 'Campagna sospesa.',
        ],

        'empty' => [
            'title' => 'Nessuna campagna',
            'body' => 'Le sponsorizzazioni si creano da qui: un evento, una collocazione, un periodo.',
        ],

        'validation' => [
            'ends_after_starts' => 'La fine deve venire dopo l\'inizio.',
        ],
    ],

    'venue' => [
        'title' => 'Sponsorizzazioni',
        'lead' => 'Le campagne attive sui tuoi eventi. Si aprono e si chiudono dalla redazione: scrivici se ne vuoi una.',
        'none' => 'Nessuna campagna sui tuoi eventi.',
    ],

];
