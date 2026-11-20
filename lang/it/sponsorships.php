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
        'heading_city' => 'Sponsorizzazioni · :city',
        'trend' => 'Andamento degli ultimi 30 giorni',
        'trend_city' => 'Andamento degli ultimi 30 giorni · :city',
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
        'create' => 'Nuova sponsorizzazione',
        'edit_title' => 'Modifica la sponsorizzazione',
        'analytics' => 'Statistiche e registro clic',

        /* Le schede del modulo di una campagna. */
        'tabs' => [
            'campaign' => 'Campagna',
            'period' => 'Periodo e limiti',
            'client' => 'Cliente e ricavo',
            'delivery' => 'Visualizzazioni',
        ],

        'sections' => [
            'what' => 'Cosa e dove',
            'when' => 'Quando',
            'who' => 'Chi la paga',
            'money' => 'Amministrazione',
            'metrics' => 'Misure',
            'caps' => 'Tetti di consegna',
            'caps_lead' => 'Un massimo che ferma la campagna. Vuoti, la campagna dura fino alla data di fine.',
            'delivery' => 'Obiettivo e distribuzione',
            'delivery_lead' => 'Con un obiettivo la campagna è venduta a visualizzazioni: le distribuisco nel periodo seguendo il traffico, e smette di comparire quando le ha date tutte. Vuoto, è venduta a periodo.',
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
            'impressions_goal' => 'Visualizzazioni comprate',
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
            'impressions_goal' => 'Lascia vuoto per vendere a periodo. Con un numero, la campagna lo consegna entro la fine e poi smette di comparire.',
            'delivery_on_grant' => 'Questa riga fa parte di un\'abilitazione: obiettivo, peso, priorità e distribuzione si cambiano nell\'abilitazione, e valgono per tutti i suoi eventi insieme.',
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
            'body' => 'Le sponsorizzazioni si creano da qui: chi paga, un evento o tutti i suoi eventi, una collocazione, un periodo.',
        ],

        'validation' => [
            'ends_after_starts' => 'La fine deve venire dopo l\'inizio.',
        ],
    ],

    /* Il selettore dell'evento da sponsorizzare (`EventPicker`). */
    'picker' => [
        'label' => 'Evento',
        'placeholder' => 'Scegli l\'evento',
        'search_prompt' => 'Scrivi il titolo dell\'evento o il nome del locale.',
        'no_results' => 'Non ho trovato eventi sponsorizzabili con queste parole. Si possono sponsorizzare solo eventi pubblicati con una data ancora da fare.',
        'no_options' => 'Nessun evento sponsorizzabile: servono eventi pubblicati con una data ancora da fare.',
        'already_sponsored' => 'Già sponsorizzato',
    ],

    /* Il percorso in quattro passi di «Nuova sponsorizzazione». */
    'wizard' => [
        'steps' => [
            'sponsor' => 'Committente',
            'sponsor_lead' => 'Chi paga',
            'what' => 'Cosa',
            'what_lead' => 'Un evento o tutti',
            'where' => 'Dove e quanto',
            'where_lead' => 'Collocazione e periodo',
            'payment' => 'Pagamento',
            'payment_lead' => 'E riepilogo',
        ],
        'fields' => [
            'sponsor_type' => 'Chi paga la sponsorizzazione?',
            'venue' => 'Locale',
            'organizer' => 'Organizzatore',
            'scope' => 'Cosa vuoi sponsorizzare?',
            'venue_filter' => 'Filtra per locale',
            'months' => 'Durata',
            'product' => 'Come si vende',
            'goal' => 'Visualizzazioni comprate',
            'pacing' => 'Distribuzione',
            'complimentary' => 'Gratuita',
            'complimentary_reason' => 'Perché è gratuita',
            'status' => 'Quando salvo',
        ],
        'sponsor_types' => [
            'venue' => 'Un locale',
            'venue_lead' => 'Il locale sponsorizza i propri eventi.',
            'organizer' => 'Un organizzatore',
            'organizer_lead' => 'L\'organizzatore sponsorizza gli eventi che firma, in qualunque locale.',
            'external' => 'Un committente esterno',
            'external_lead' => 'Qualcuno che non è né il locale né l\'organizzatore: per esempio un\'etichetta che promuove un concerto in un circolo.',
        ],
        'placeholders' => [
            'venue' => 'Cerca il locale per nome',
            'search_venue' => 'Scrivi il nome del locale.',
            'no_venue' => 'Non ho trovato locali approvati con questo nome.',
            'organizer' => 'Cerca l\'organizzatore per nome',
            'search_organizer' => 'Scrivi il nome dell\'organizzatore.',
            'no_organizer' => 'Non ho trovato organizzatori attivi con questo nome.',
            'venue_filter' => 'Tutti i locali',
            'custom_duration' => 'Date a scelta',
        ],
        'sections' => [
            'advertiser' => 'Nome e contatti del committente',
            'advertiser_lead' => 'Si compilano dal locale o dall\'organizzatore scelto e si possono cambiare. Il nome compare al pubblico accanto alla scritta «Sponsorizzato».',
            'period' => 'Periodo',
            'period_lead' => 'Date e ore nel fuso della città (:timezone).',
            'product' => 'Prodotto',
            'payment' => 'Pagamento',
            'advanced' => 'Opzioni avanzate',
            'advanced_lead' => 'Priorità e peso. Di solito non servono: lasciale come sono se non sai di averne bisogno.',
        ],
        'scopes' => [
            'event' => 'Un evento',
            'event_lead' => 'Una campagna su un evento scelto da te.',
            'all' => 'Tutti gli eventi',
            'all_lead' => 'Un\'abilitazione: tutti gli eventi di oggi e quelli che arriveranno, con un avanzamento solo.',
        ],
        'help' => [
            'venue_filter' => 'Facoltativo: restringe l\'elenco degli eventi a un locale.',
            'event_sponsor' => 'Solo gli eventi del committente pubblicati e con una data ancora da fare. Puoi cercare per titolo o per locale.',
            'event_external' => 'Tutti gli eventi pubblicati con una data ancora da fare. Puoi cercare per titolo o per nome del locale.',
            'hero_period_only' => 'L\'apertura della home si vende solo a periodo: resta uguale per tutti per mezz\'ora alla volta, quindi non posso garantire un numero preciso di visualizzazioni.',
            'map_period_only' => 'Il foglio della mappa si vende solo a periodo: lo vede solo chi apre il locale sulla mappa, quindi non posso garantire un numero preciso di visualizzazioni.',
            'period_only' => 'L\'apertura della home e il foglio della mappa si vendono solo a periodo: scegli «a periodo» oppure un\'altra collocazione.',
            'goal' => 'Quante visualizzazioni prometti. La campagna smette di comparire quando le ha date tutte.',
            'complimentary' => 'Una sponsorizzazione senza pagamento, per esempio un patrocinio. Il motivo è obbligatorio.',
            'paid_at' => 'Senza data di pagamento l\'abilitazione resta in attesa e gli eventi non compaiono.',
            'price_from_list' => 'Dal listino: :price per :months.',
            'price_none' => 'Nessuna voce di listino per questa collocazione e durata: scrivi tu l\'importo.',
        ],
        'products' => [
            'period' => 'A periodo',
            'period_lead' => 'Compare per tutto il periodo, a rotazione con le altre di pari priorità.',
            'views' => 'A visualizzazioni',
            'views_lead' => 'Un numero di visualizzazioni da consegnare entro la fine del periodo.',
        ],
        'months' => '{1} 1 mese|[2,*] :count mesi',
        'statuses' => [
            'active' => 'Attiva subito',
            'draft' => 'Bozza: la salvo ma non compare',
        ],
        'estimate' => [
            'heading' => 'In questo periodo lo spazio può dare circa :views visualizzazioni.',
            'days' => '{1} Calcolate su un giorno di dati.|[2,*] Calcolate su :count giorni di dati.',
            'no_data' => 'Non ho ancora dati misurati: è un valore di partenza, non una misura.',
            'provisional' => 'Stima provvisoria.',
            'ok' => 'L\'obiettivo ci sta.',
            'warning' => 'L\'obiettivo chiede il :share% dello spazio: è molto, e nello stesso periodo resterebbe poco agli altri.',
            'blocked' => 'L\'obiettivo supera l\'80% di quello che lo spazio può dare, il massimo che do a una sola campagna: allunga il periodo o abbassa il numero.',
        ],
        'all_events' => [
            'count' => '{0} :sponsor oggi non ha eventi sponsorizzabili.|{1} :sponsor oggi ha un evento sponsorizzabile.|[2,*] :sponsor oggi ha :count eventi sponsorizzabili.',
            'lead' => 'Gli eventi nuovi si aggiungono da soli, finché l\'abilitazione è attiva.',
            'more' => '{1} E un altro evento.|[2,*] E altri :count eventi.',
            'no_sponsor' => 'Scegli prima il committente.',
        ],
        'occupants' => [
            'heading' => 'Chi occupa già questo spazio',
            'waiting' => 'Scegli collocazione e periodo per vedere chi c\'è già.',
            'empty' => 'Nessuno: in questo periodo lo spazio è libero.',
            'lead' => '{1} Nello stesso periodo c\'è già una campagna: si alterneranno.|[2,*] Nello stesso periodo ci sono già :count campagne: si alterneranno.',
            'hero_warning' => 'C\'è già un\'altra campagna in apertura della home: con l\'apertura le quote si rispettano meno bene, perché la scelta resta ferma mezz\'ora alla volta.',
            'single' => 'un evento',
            'events' => '{0} nessun evento per ora|{1} un evento|[2,*] :count eventi',
            'period' => 'a periodo',
            'goal' => ':goal visualizzazioni',
        ],
        'summary' => [
            'heading' => 'Riepilogo',
            'lead_single' => 'Salvando creo una campagna su questo evento.',
            'lead_grant' => 'Salvando creo un\'abilitazione per tutti gli eventi e aggiungo subito quelli di oggi.',
            'advertiser' => 'Committente',
            'what' => 'Cosa',
            'all_events' => '{0} Tutti gli eventi di :sponsor (oggi nessuno)|{1} Tutti gli eventi di :sponsor (oggi uno)|[2,*] Tutti gli eventi di :sponsor (oggi :count)',
            'where' => 'Collocazione',
            'when' => 'Periodo',
            'product' => 'Prodotto',
            'period' => 'A periodo',
            'views' => ':goal visualizzazioni, :pacing',
            'payment' => 'Pagamento',
            'complimentary' => 'Gratuita',
            'no_amount' => 'Nessun importo',
            'status' => 'Stato',
            'advanced' => 'Priorità e peso',
            'advanced_value' => 'priorità :priority, peso :weight',
            'missing' => 'Da scegliere',
            'unpaid_warning' => 'Manca la data di pagamento: l\'abilitazione resta in attesa e gli eventi non compaiono finché non la registri.',
            'grant_name' => 'Nella fascia «Sponsorizzato» degli eventi comparirà il nome «:name».',
        ],
        'validation' => [
            'sponsor_required' => 'Scegli il locale o l\'organizzatore.',
            'event_not_sponsorable' => 'Questo evento non si può sponsorizzare: deve essere del committente, pubblicato e con una data ancora da fare.',
            'goal_blocked' => 'Lo spazio in questo periodo può dare circa :estimate visualizzazioni, e a una sola campagna ne do al massimo :max: allunga il periodo o abbassa l\'obiettivo.',
        ],
        'created' => 'Campagna creata',
        'created_grant' => '{0} Abilitazione creata: per ora nessun evento da mostrare|{1} Abilitazione creata con un evento|[2,*] Abilitazione creata con :count eventi',
    ],

    /* Come un'unità a visualizzazioni spende il suo obiettivo. */
    'pacing' => [
        'even' => 'Distribuita nel periodo',
        'even_lead' => 'Seguendo il traffico ora per ora, fino alla fine.',
        'asap' => 'Il prima possibile',
        'asap_lead' => 'Con la quota più alta finché non arriva all\'obiettivo.',
    ],

    /* A che punto è un'unità a visualizzazioni (`PacingState`), più «a periodo». */
    'pacing_state' => [
        'on_track' => 'In linea',
        'slightly_behind' => 'In leggero ritardo',
        'behind' => 'In ritardo',
        'ahead' => 'In anticipo',
        'at_risk' => 'A rischio',
        'completed' => 'Obiettivo raggiunto',
        'missed' => 'Chiusa sotto l\'obiettivo',
        'period' => 'A periodo',
    ],

    /* L'avanzamento negli elenchi e nella scheda. */
    'progress' => [
        'column' => 'Avanzamento',
        'filter' => 'Avanzamento',
        'heading' => 'Avanzamento',
        'lead' => 'Quante visualizzazioni ha dato e come sta andando rispetto all\'atteso.',
        'of_goal' => ':delivered di :goal',
        'views_only' => '{0} Nessuna visualizzazione|{1} Una visualizzazione|[2,*] :count visualizzazioni',
        'sentence' => ':delivered di :goal visualizzazioni: :state.',
        'period_sentence' => '{0} Nessuna visualizzazione finora. È venduta a periodo, senza obiettivo.|{1} Una visualizzazione finora. È venduta a periodo, senza obiettivo.|[2,*] :count visualizzazioni finora. È venduta a periodo, senza obiettivo.',
        'bar_legend' => '{0} La linea sulla barra è l\'atteso a oggi: ancora nessuna visualizzazione.|{1} La linea sulla barra è l\'atteso a oggi: circa una visualizzazione.|[2,*] La linea sulla barra è l\'atteso a oggi: circa :expected visualizzazioni.',
        'true_end' => 'Consegna fino a :date',
        'quota_over' => 'oltre il :max%',
        'delivered_today' => 'Consegnate oggi',
        'delivered_today_hint' => 'Dalla mezzanotte, nel fuso della città.',
        'required_quota' => 'Quota richiesta',
        'required_quota_hint' => 'La parte dello spazio che le serve da adesso per arrivare all\'obiettivo.',
        'days_left' => 'Giorni rimasti',
        'days_left_hint' => 'Fino alla fine vera: la fine del periodo o l\'ultima data degli eventi, se viene prima.',
        'provisional' => 'La previsione del traffico è provvisoria: ci sono meno di :days giorni di dati misurati.',
        'daily' => 'Giorno per giorno',
        'daily_empty' => 'Il periodo non è ancora cominciato.',
        'daily_row' => ':delivered consegnate · :expected attese',
        'events' => 'Gli eventi',
        'events_lead' => 'La quota è la parte delle visualizzazioni che ogni evento riceve oggi dentro l\'abilitazione: di più a chi ha la data più vicina.',
        'event_numbers' => ':views visualizzazioni · :clicks aperture',
        'event_share' => 'quota :share%',
        'event_no_share' => 'nessuna data in arrivo',
        'no_unit' => 'Non riesco a calcolare l\'avanzamento: manca la città della campagna.',
    ],

    /* Le abilitazioni («Abilitazioni e pagamenti»). */
    'grant' => [
        'delivery' => 'Consegna',
        'edit_title' => 'Modifica l\'abilitazione',
        'delivery_lead' => 'Valgono per tutti gli eventi dell\'abilitazione insieme. Con un obiettivo l\'abilitazione è venduta a visualizzazioni; vuoto, a periodo.',
    ],

    'venue' => [
        'title' => 'Sponsorizzazioni',
        'lead' => 'Le campagne sui tuoi eventi e come richiederne una nuova.',
        'none' => 'Nessuna campagna sui tuoi eventi, per ora.',
        'analytics' => 'Statistiche e registro clic',
    ],

    /* La pagina dei banner orizzontali, in redazione. */
    'banners' => [
        'lead' => 'I banner orizzontali mostrano automaticamente foto, titolo, data, locale e prezzo degli eventi sponsorizzati, con la dicitura AD. Le campagne si alternano rispettando priorità, peso e limiti già impostati.',
        'rules' => 'Gli eventi conclusi non vengono più sponsorizzati dal giorno successivo, nel fuso della città. Le date annullate, rinviate o spostate sono escluse. Restano validi solo gli eventi con almeno una data valida.',
        'save' => 'Salva impostazioni',
        'switch_off' => 'Lo spegnimento riguarda questi banner aggiuntivi, non le collocazioni originali delle campagne. Si applica subito alle nuove richieste e, entro un minuto, alle pagine e alle app già aperte e connesse.',
    ],

    /* La pagina delle statistiche, uguale nei tre pannelli. */
    'analytics' => [
        'title' => 'Statistiche sponsorizzazioni',
        'lead' => 'Visualizzazioni e clic delle campagne, anche concluse. Scegli il periodo o cerca un evento.',
        'period' => 'Periodo',
        'periods' => ['7' => 'Ultimi 7 giorni', '30' => 'Ultimi 30 giorni', '90' => 'Ultimi 90 giorni', '365' => 'Ultimo anno', 'all' => 'Tutto lo storico', 'custom' => 'Fra due date'],
        'from' => 'Dal',
        'to' => 'Al',
        'campaign' => 'Campagna o evento',
        'all_campaigns' => 'Tutte le campagne',
        'window' => 'Dal :from al :to, giorni nel fuso della città.',
        'totals' => 'Nel periodo',
        'impressions' => 'Visualizzazioni',
        'clicks' => 'Clic registrati',
        'rate' => 'Clic ogni 100 visualizzazioni',
        'not_available' => 'Non disponibile',
        'chart_impressions' => 'Visualizzazioni giornaliere',
        'chart_clicks' => 'Clic giornalieri',
        'chart_empty' => 'Nessun dato in questo periodo.',
        'chart_label' => ':label. Massimo :max. I valori esatti sono nell’elenco giorno per giorno.',
        'peak' => 'Massimo: :max',
        'daily' => 'Valori giorno per giorno',
        'daily_row' => ':views visualizzazioni · :clicks clic',
        'register' => 'Registro dei clic',
        'register_lead' => 'Una riga per ogni clic accettato dai controlli antiabuso, con l’ora nel fuso della città. Nessun IP o dato personale viene conservato. I clic bloccati dal browser o senza connessione non si possono misurare.',
        'register_empty' => 'Nessun clic nel registro per questo periodo.',
        'event_gone' => 'Evento non più disponibile',
        'campaign_ref' => 'campagna n. :id',
        'channels' => ['web' => 'Sito', 'android' => 'Android'],
        'placements' => ['banner' => 'Banner orizzontale', 'home_hero' => 'Apertura home', 'card' => 'Scheda sponsorizzata'],
        'pages' => ['home' => 'Home', 'feed' => 'Il mio feed', 'event' => 'Evento', 'venues' => 'Locali', 'profile' => 'Profilo', 'search' => 'Ricerca', 'other' => 'Pagina non specificata'],
    ],

];
