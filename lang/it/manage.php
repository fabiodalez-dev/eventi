<?php

declare(strict_types=1);

/*
 * Pannello dei locali `/gestione` (§10 del piano).
 *
 * Il vocabolario è diverso da quello di `lang/it/admin.php` e non per svista:
 * la redazione parla di occorrenze, verifica e punteggio editoriale, un
 * gestore parla di date, locandina e quanto costa. Le due lingue restano
 * separate perché il giorno in cui una delle due cambia, l'altra non deve
 * cambiare con lei.
 *
 * Le etichette degli stati non stanno qui: sono in `lang/it/enums.php`, dove
 * le leggono i metodi `label()` degli enum.
 */
return [

    'dashboard' => [
        'title' => 'Riepilogo',
        'subheading' => 'Come vanno i tuoi eventi.',
        'today' => 'Oggi',
        'today_hint' => 'Date in programma nella giornata di oggi.',
        'upcoming' => 'Prossimi',
        'upcoming_hint' => 'Date da oggi in avanti.',
        'views' => 'Visualizzazioni',
        'views_hint' => 'Negli ultimi :days giorni.',
        'today_schedule' => 'In programma oggi',
    ],

    'resources' => [
        'event' => ['label' => 'Evento', 'plural' => 'I tuoi eventi'],
        'occurrence' => ['label' => 'Data', 'plural' => 'Date'],
        'lineup' => ['label' => 'Artista', 'plural' => 'Chi suona'],
    ],

    'sections' => [
        'what' => 'Che cosa è',
        'taxonomy' => 'Che genere di serata',
        'price' => 'Quanto costa',
        'links' => 'Link',
        'ticket_tiers' => 'Fasce di prezzo',
        'facts' => 'Scheda tecnica',
        /* Sezioni della schermata «Il tuo locale» (§10). */
        'venue_identity' => 'Chi siete',
        'venue_images' => 'Immagini',
        'venue_images_lead' => 'Il logo compare accanto al nome, la copertina in cima alla vostra scheda.',
        'venue_where' => 'Dove siete',
        'venue_hours' => 'Quando siete aperti',
        'venue_contacts' => 'Come vi si contatta',
        'venue_transit' => 'Come si arriva',
        'venue_accessibility' => 'Accessibilità',
        'venue_info' => 'Buono a sapersi',
        'venue_membership' => 'Tesseramento',
    ],

    'location_missing' => [
        'title' => 'Non siete ancora sulla mappa',
        'body' => 'Il vostro segnaposto è sul centro della città insieme a tutti gli altri: spostatelo sulla vostra porta, così chi cerca «vicino a me» vi trova davvero.',
        'action' => 'Mettici sulla mappa',
    ],

    'fields' => [
        'source' => 'Provenienza',
        'poster' => 'Locandina',
        'title' => 'Titolo',
        'description' => 'Descrizione',
        'starts_at' => 'Inizio',
        'ends_at' => 'Fine',
        'next_date' => 'Prossima data',
        'category' => 'Categoria',
        'tags' => 'Tag',
        'price_type' => 'Prezzo',
        'price_min' => 'Da (€)',
        'price_max' => 'A (€)',
        'ticket_url' => 'Link per i biglietti',
        'booking_url' => 'Link per prenotare',
        'external_links' => 'Altri link',
        'external_link_label' => 'Che link è',
        'external_link_url' => 'Indirizzo',
        'status' => 'Stato',
        'occurrence_status' => 'Stato della data',
        'artist' => 'Nome',
        'artist_role' => 'Come si esibisce',
        'name' => 'Nome',
        'email' => 'Email',
        'role' => 'Ruolo',
        'accepted_at' => 'Ha accettato il',
        'fact_label' => 'Voce',
        'fact_value' => 'Valore',
        'transit_mode' => 'Mezzo',
        'transit_text' => 'Indicazione',
        'tier_name' => 'Settore',
        'tier_price' => 'Prezzo',
        'tier_status' => 'Stato',
        'tier_url' => 'Dove si comprano',
        'tier_note' => 'Nota',
        'occurrence_capacity' => 'Capienza di questa data',
        'occurrence_capacity_left' => 'Posti ancora liberi',
        'occurrence_highlight' => 'Etichetta di richiamo',
        /* «Il tuo locale» */
        'venue_name' => 'Nome',
        'venue_type' => 'Tipo di locale',
        'short_description' => 'In due righe',
        'logo' => 'Logo',
        'logo_help' => 'Quadrato o quasi, su fondo trasparente se ce l\'avete.',
        'cover' => 'Immagine di copertina',
        'cover_help' => 'Orizzontale: è la prima cosa che si vede sulla vostra scheda.',
        'gallery' => 'Galleria',
        'gallery_help' => 'Qualche foto del posto. Si trascinano per cambiarne l\'ordine.',
        'map' => 'Dove siete, sulla mappa',
        'map_help' => 'Trascina il segnaposto sulla porta d\'ingresso: è il punto che vede chi cerca «vicino a me».',
        'address' => 'Indirizzo',
        'address_extra' => 'Interno, scala, indicazioni',
        'postal_code' => 'CAP',
        'municipality' => 'Comune',
        'zone' => 'Quartiere',
        'phone' => 'Telefono',
        'website' => 'Sito',
        'socials' => 'Social',
        'capacity' => 'Capienza',
        'opening_hours' => 'Orari di apertura',
        'opening_day' => 'Giorno',
        'opening_from' => 'Apre',
        'opening_to' => 'Chiude',
        'requires_membership' => 'Serve la tessera',
        'membership_notes' => 'Come ci si tessera',
    ],

    'hints' => [
        'poster' => 'La locandina è la prima cosa che si vede. Va bene anche la foto scattata al volantino.',
        'ends_at' => 'Solo se la sai. Altrimenti la calcoliamo dalla categoria.',
        'tags' => 'Facoltativi: aiutano a farti trovare da chi cerca un genere preciso.',
        'price_max' => 'Solo se il prezzo cambia (per esempio in prevendita e alla porta).',
        'ticket_url' => 'Dove si comprano i biglietti online, se ci sono.',
        'booking_url' => 'Dove si prenota un posto o un tavolo, se serve.',
        'external_links' => 'L\'evento su Facebook, il tuo sito, un articolo che ne parla. Fino a :max, tutti facoltativi.',
        'facts' => 'Le cose che la gente chiede sempre: a che ora si apre, quanto dura, da che età. Fino a :max righe.',
        'venue_info' => 'Quello che vale per il locale e non per la singola serata: guardaroba, regole della sala. Fino a :max righe.',
        'transit' => 'Una riga per mezzo. La linea e la fermata scrivile nel testo: «Tram 6, fermata Ospedali, cinque minuti a piedi». Fino a :max.',
        'ticket_tiers' => 'Vale per tutte le date di questo evento. Puoi segnare esaurito un solo settore: la serata resta in vendita.',
        'tier_price' => 'Vuoto se il prezzo non c\'è ancora. Zero vuol dire ingresso libero.',
        'occurrence_capacity' => 'Solo se questa sera i posti sono diversi dal solito.',
        'occurrence_capacity_left' => 'Quanti ne restano: lo mostriamo sulla card.',
        'occurrence_highlight' => 'Due parole in evidenza: «Ultimi posti», «Nuova data».',
        'zone' => 'Il quartiere. In città la gente cerca così, il comune è lo stesso per tutti.',
        'venue_name' => 'Il nome lo cambia la redazione: scrivici se è sbagliato.',
        'capacity' => 'Quante persone entrano. Serve a dire quanti posti restano su una serata.',
    ],

    'placeholders' => [
        'title' => 'Per esempio: Concerto dei Marlene Kuntz',
        'description' => 'Due righe bastano. Puoi anche lasciare vuoto.',
        'ticket_url' => 'https://…',
        'url' => 'https://…',
        'no_date' => 'Nessuna data',
        'not_declared' => 'Non dichiarato',
        'pending_invite' => 'Invito da accettare',
    ],

    'actions' => [
        'new_event' => 'Nuovo evento',
        'edit' => 'Modifica',
        'duplicate' => 'Duplica',
        'view_on_site' => 'Vedi sul sito',
        'publish' => 'Pubblica',
        'publish_confirm' => 'Da questo momento l’evento è visibile a chi cerca cosa fare in città.',
        'repeat' => 'Ripeti',
        'add_date' => 'Aggiungi una data',
        'cancel_date' => 'Annulla la data',
        'mark_sold_out' => 'Segna tutto esaurito',
        'mark_available' => 'Ci sono ancora posti',
        'add_lineup' => 'Aggiungi chi suona',
        'add_external_link' => 'Aggiungi un link',
        'add_fact' => 'Aggiungi una riga',
        'add_transit' => 'Aggiungi un mezzo',
        'add_ticket_tier' => 'Aggiungi una fascia',
        'add_opening_hours' => 'Aggiungi un orario',
        'sold_out_next' => 'Tutto esaurito',
        'sold_out_next_confirm' => 'Segniamo esaurita la prossima data: :date. Puoi rimetterla in vendita quando vuoi.',
        'available_next' => 'Ci sono ancora posti',
        'available_next_confirm' => 'Rimettiamo in vendita la prossima data: :date.',
        'save_venue' => 'Salva',
    ],

    /*
     * «Il tuo locale» (§10): i campi del locale che fino a oggi esistevano
     * nello schema e non erano mai stati mostrati a chi dovrebbe compilarli.
     */
    'venue' => [
        'title' => 'Il tuo locale',
        'subheading' => 'Quello che si legge sulla tua pagina: dove siete, quando siete aperti, come ci si arriva.',
        'saved' => 'Dati del locale aggiornati',
        'saved_body' => 'La pagina pubblica del locale è già cambiata.',
        'read_only' => 'Questi dati li modifica il referente del locale.',
    ],

    'accessibility' => [
        'yes' => 'Sì',
        'no' => 'No',
        'undeclared' => 'Non dichiarato',
    ],

    'wizard' => [
        'title' => 'Nuovo evento',
        'shortcuts' => 'Scorciatoie',
        'finish' => 'Pubblica',
        'step_poster' => 'Locandina',
        'step_poster_hint' => 'La foto e il nome della serata.',
        'step_when' => 'Quando',
        'step_when_hint' => 'Giorno e ora.',
        'step_category' => 'Genere',
        'step_category_hint' => 'Serve a farti trovare.',
        'step_price' => 'Prezzo',
        'step_price_hint' => 'Anche solo "gratis".',
        'step_details' => 'Dettagli',
        'step_details_hint' => 'Facoltativi: puoi pubblicare senza.',
    ],

    'shortcuts' => [
        'tonight' => 'Stasera',
        'tomorrow' => 'Domani',
        'friday' => 'Venerdì',
        'every_thursday' => 'Ogni giovedì',
    ],

    'recurrence' => [
        'toggle' => 'Si ripete',
        'toggle_hint' => 'Per le serate fisse: la creiamo una volta e la mettiamo in calendario per tutte le date.',
        'heading' => 'Ripeti questo evento',
        'description' => 'Le date le mettiamo in calendario noi, fino alla data che indichi.',
        'current' => 'Adesso si ripete :rule. Puoi cambiare la regola qui sotto.',
        'submit' => 'Metti in calendario',
        'frequency' => 'Ogni quanto',
        'weekdays' => 'In quali giorni',
        'weekdays_hint' => 'Se non scegli nulla, usiamo il giorno della prima data.',
        'until' => 'Fino al',
        'until_hint' => 'Lascia vuoto per andare avanti a tempo indeterminato.',
        'summary' => ':frequency il :days',
    ],

    'statistics' => [
        'title' => 'Statistiche',
        'subheading' => 'Ultimi :days giorni. Nessun dato personale di chi guarda.',
        'views' => 'Visualizzazioni',
        'saves' => 'Salvataggi',
        'directions' => 'Indicazioni',
        'tickets' => 'Biglietti',
        'per_event' => 'Evento per evento',
    ],

    'collaborators' => [
        'title' => 'Collaboratori',
        'subheading' => 'Chi può pubblicare gli eventi di questo locale.',
        'invite' => 'Invita un collaboratore',
        'invite_description' => 'Riceverà un’email con il collegamento per entrare. Potrà gestire gli eventi, non i dati del locale.',
        'invite_submit' => 'Manda l’invito',
        'invited' => 'Invito inviato',
        'invited_body' => 'Abbiamo scritto a :email.',
        'remove' => 'Rimuovi',
        'remove_confirm' => 'Non potrà più pubblicare eventi per questo locale. Gli eventi già inseriti restano.',
        'removed' => 'Collaboratore rimosso',
    ],

    'invitation' => [
        'subject' => ':venue su :product',
        'greeting' => 'Ciao :name,',
        'intro' => 'Da adesso puoi pubblicare gli eventi di :venue su :product, come :role.',
        'password_hint' => 'Scegli una password per entrare: il collegamento vale un’ora.',
        'password_action' => 'Scegli la password',
        'ignore' => 'Se non ti aspettavi questo messaggio, puoi ignorarlo.',
        'open_action' => 'Apri il pannello',
    ],

    'notifications' => [
        'published' => 'Evento pubblicato',
        'published_body' => 'È online: chi cerca cosa fare in città lo vede.',
        'sent_to_review' => 'Evento inviato alla redazione',
        'sent_to_review_body' => 'Lo controlliamo e lo pubblichiamo noi. Ti avvisiamo appena è online.',
        'duplicated' => 'Copia creata',
        'duplicated_body' => 'Cambia data e ora, poi pubblica.',
        'dates_created' => 'Aggiunta :count data|Aggiunte :count date',
        'no_new_dates' => 'Nessuna data nuova da aggiungere',
        'dates_updated' => 'Modificata :count data|Modificate :count date',
        'nothing_to_do' => 'Non c’era nulla da cambiare',
    ],

    /*
     * §14.2 — collegare il calendario del locale.
     */
    'calendar' => [
        'title' => 'Calendario collegato',
        'subheading' => 'Collega il calendario che usi gia e le tue date arrivano da sole.',

        'form' => [
            'heading' => 'Indirizzo del calendario',
            'help' => 'Serve il collegamento in formato iCal (finisce per .ics). In Google Calendar lo trovi in Impostazioni del calendario, sezione «Integra calendario»: usa l\'indirizzo pubblico se il calendario e pubblico, altrimenti l\'indirizzo segreto in formato iCal.',
            'url' => 'Collegamento al calendario',
            'url_help' => 'Incolla qui l\'indirizzo. Lo leggiamo ogni ora e teniamo aggiornate le tue date.',
            'exclude' => 'Parole che tengono fuori una data',
            'exclude_help' => 'Le date il cui titolo contiene una di queste parole non vengono pubblicate. Servono a lasciare fuori le voci interne del tuo calendario, come le riunioni o le chiusure.',
            'exclude_placeholder' => 'Aggiungi una parola',
        ],

        'preview_required' => 'Guarda prima l\'anteprima: le date che vedi sono esattamente quelle che verranno pubblicate.',

        'preview' => [
            'heading' => 'Cosa verra pubblicato',
            'description' => 'Le prime date del tuo calendario, con il filtro gia applicato.',
        ],

        'status' => [
            'heading' => 'Stato del collegamento',
            'state' => 'Stato',
            'active' => 'Attivo',
            'suspended' => 'Sospeso',
            'last_run' => 'Ultima lettura',
            'never' => 'Mai',
            'outcome' => 'Esito',
            'address' => 'Indirizzo',
        ],

        'actions' => [
            'preview' => 'Anteprima',
            'connect' => 'Collega il calendario',
            'update' => 'Aggiorna il collegamento',
            'run_now' => 'Leggi adesso',
            'suspend' => 'Sospendi',
            'resume' => 'Riattiva',
            'disconnect' => 'Scollega',
            'disconnect_confirm' => 'Le date gia pubblicate restano dove sono: si interrompe solo la lettura del calendario.',
        ],

        'notifications' => [
            'preview_first' => 'Guarda l\'anteprima prima di collegare.',
            'connected' => 'Calendario collegato.',
            'connected_body' => 'La prima lettura e in corso: fra poco troverai le tue date fra gli eventi.',
            'run_done' => 'Lettura completata.',
            'run_failed' => 'La lettura non e riuscita.',
            'suspended' => 'Collegamento sospeso.',
            'resumed' => 'Collegamento riattivato.',
            'disconnected' => 'Calendario scollegato.',
        ],
    ],
];
