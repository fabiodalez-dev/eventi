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

    /* Il riepilogo in cima alla pagina delle campagne. */
    /* Usati sia dal grafico sia dal riepilogo per il committente. */
    'metrics' => [
        'impressions' => 'Visualizzazioni',
        'clicks' => 'Aperture',
    ],

    /* Il riepilogo settimanale al committente. */
    'report' => [
        'subject' => 'Come sta andando: :evento',
        'greeting' => 'Ciao :nome,',
        'window' => 'Ecco il riepilogo dal :da al :a.',
        'rate' => 'Sono :valore aperture ogni cento visualizzazioni.',
        'total' => 'Da inizio campagna: :viste visualizzazioni e :aperture aperture.',
        'until' => 'La campagna resta attiva fino al :data.',
        'see_event' => 'Vedi l\'evento sul sito',
        'how_measured' => 'Le misure si contano dal browser di chi visita il sito: chi blocca gli script non viene conteggiato, quindi questi numeri sono una stima al ribasso e non un registro contabile.',
    ],

    'widgets' => [
        'running' => 'In corso adesso',
        'running_lead' => 'Campagne visibili sul sito in questo momento',
        'expiring' => 'In scadenza',
        'expiring_lead' => 'Finiscono entro sette giorni: è il momento di richiamare chi paga',
        'week_impressions' => 'Viste in 7 giorni',
        'week_lead' => 'Somma di tutte le campagne',
        'week_rate' => 'Aperture su viste',
        'week_rate_lead' => ':aperture aperture nella settimana',
        'week_rate_none' => 'Nessuna visualizzazione ancora: non c\'è niente da rapportare',
        'trend' => 'Andamento degli ultimi 30 giorni',
        'trend_lead' => 'La distanza fra le due linee dice se le campagne servono a qualcosa, non solo quanto sono state mostrate.',
    ],

    /*
     * A che punto e' una campagna. Si ricava da stato piu' finestra, quindi
     * dice il vero anche se nessun processo notturno gira da una settimana.
     */
    'phase' => [
        'draft' => 'Bozza',
        'scheduled' => 'Programmata',
        'running' => 'In corso',
        'ended' => 'Finita',
        'paused' => 'Sospesa',
    ],

    'label' => 'Sponsorizzato',
    'by' => 'a cura di :advertiser',

    'admin' => [
        'title' => 'Sponsorizzazioni',
        'singular' => 'Sponsorizzazione',
        'navigation' => 'Sponsorizzazioni',

        /* Le schede del modulo di una campagna. */
        'tabs' => [
            'campaign' => 'Campagna',
            'period' => 'Periodo e limiti',
            'client' => 'Cliente e ricavo',
        ],

        'sections' => [
            'what' => 'Cosa e dove',
            'when' => 'Quando',
            'who' => 'Chi la paga',
            'money' => 'Amministrazione',
            'metrics' => 'Misure',
            'caps' => 'Tetti di consegna',
            'caps_lead' => 'Per vendere a numero di visualizzazioni invece che a tempo. Vuoti, la campagna dura fino alla data di fine.',
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
            'phase' => 'A che punto è',
            'weight' => 'Peso in rotazione',
            'impressions_cap' => 'Tetto visualizzazioni',
            'clicks_cap' => 'Tetto aperture',
        ],

        'help' => [
            'placement' => 'Ogni collocazione ne ammette un numero fisso: se ce ne sono di più, si alternano.',
            'priority' => 'A parità di finestra e collocazione, chi ha il numero più alto compare per primo. Le altre restano in rotazione.',
            'advertiser_name' => 'Compare al pubblico accanto alla scritta «Sponsorizzato»: è chi paga, e può non essere il locale.',
            'window' => 'Fuori da questa finestra la campagna non compare, qualunque sia lo stato.',
            'metrics' => 'Misurate dal browser: chi blocca gli script non viene contato, quindi sono una stima al ribasso.',
            'event_not_published' => 'Questo evento non è pubblicato: la campagna non comparirà finché non lo sarà.',
            'weight' => 'Quante volte compare rispetto alle altre di pari priorità: peso 3 contro peso 1 significa tre volte su quattro. Serve a vendere lo stesso spazio a più clienti senza che nessuno resti a zero.',
            'impressions_cap' => 'Lasciare vuoto per nessun tetto. Raggiunto il numero, la campagna smette di comparire ma non cambia stato: è finita per esaurimento, non sospesa.',
            'clicks_cap' => 'Come sopra, ma sulle aperture.',
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
