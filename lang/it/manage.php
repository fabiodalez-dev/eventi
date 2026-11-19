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

    'navigation' => [
        'analytics' => 'Statistiche',
        'community' => 'Community',
        'profile' => 'Il mio profilo',
        'tickets' => 'Biglietti e partecipanti',
    ],

    'dashboard' => [
        'title' => 'Riepilogo',
        'subheading' => 'Come vanno i tuoi eventi.',
        'today' => 'Oggi',
        'today_hint' => 'Date in programma nella giornata di oggi.',
        'upcoming' => 'Prossimi',
        'upcoming_hint' => 'Date da oggi in avanti.',
        'today_schedule' => 'In programma oggi',
        'today_schedule_description' => 'Le serate di oggi, con quello che serve a portata di mano.',
        'drafts' => 'Bozze',
        'drafts_hint' => 'Eventi da completare o pubblicare.',
        'summary' => 'Il tuo cartellone',
        'summary_description' => 'Quante serate hai oggi, quante in arrivo e cosa resta da pubblicare.',
        'upcoming_heading' => 'Prossimi eventi',
        'upcoming_description' => 'Le prossime date in cartellone. Tocca un evento per modificarlo, vederne le statistiche, preparare le grafiche o sponsorizzarlo.',
        'upcoming_empty' => 'Nessuna data in arrivo',
        'upcoming_empty_body' => 'Crea un evento: bastano titolo, data, categoria e prezzo, il resto si aggiunge quando vuoi.',
        'all_events' => 'Tutti i tuoi eventi',
        'recent_views' => 'Ultime visite',
        'recent_views_description' => 'Le schede più aperte negli ultimi :days giorni.',
        'views_count' => '{1} :count visita|[2,*] :count visite',
        'open_statistics' => 'Apri le statistiche',
        'shortcuts' => 'Vai a',
        'shortcuts_description' => 'Tutte le pagine del pannello, a un tocco.',
        'group_events' => 'Eventi',
        'group_audience' => 'Pubblico e numeri',
        'group_venue' => 'Il tuo locale',
        'group_organizer' => 'La tua organizzazione',
        'event_actions' => 'Azioni per :title',
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
        'ends_at' => 'Solo se la sai. Altrimenti la durata si calcola dalla categoria.',
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
        'occurrence_capacity_left' => 'Quanti ne restano: il numero compare sulla card.',
        'occurrence_highlight' => 'Due parole in evidenza: «Ultimi posti», «Nuova data».',
        'zone' => 'Il quartiere. In città la gente cerca così, il comune è lo stesso per tutti.',
        'venue_name' => 'Il nome lo cambia la redazione: scrivimi se è sbagliato.',
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
        'sold_out_next_confirm' => 'La prossima data, :date, diventa esaurita. Puoi rimetterla in vendita quando vuoi.',
        'available_next' => 'Ci sono ancora posti',
        'available_next_confirm' => 'La prossima data, :date, torna in vendita.',
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
        'finish' => 'Pubblica',
    ],

    'shortcuts' => [
        'tonight' => 'Stasera',
        'tomorrow' => 'Domani',
        'friday' => 'Venerdì',
        'every_thursday' => 'Ogni giovedì',
    ],

    'recurrence' => [
        'toggle' => 'Si ripete',
        'toggle_hint' => 'Per le serate fisse: la crei una volta e va in calendario per tutte le date.',
        'heading' => 'Ripeti questo evento',
        'description' => 'Le date vanno in calendario da sole, fino al giorno che indichi.',
        'current' => 'Adesso si ripete :rule. Puoi cambiare la regola qui sotto.',
        'submit' => 'Metti in calendario',
        'frequency' => 'Ogni quanto',
        'weekdays' => 'In quali giorni',
        'weekdays_hint' => 'Se non scegli nulla, vale il giorno della prima data.',
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
        'invited_body' => 'L’invito è partito verso :email.',
        'invite_limit' => 'Hai mandato molti inviti di seguito. Riprova più tardi.',
        'already_member' => 'Questa persona è già fra chi gestisce il locale.',
        'remove' => 'Rimuovi',
        'remove_confirm' => 'Non potrà più pubblicare eventi per questo locale. Gli eventi già inseriti restano.',
        'removed' => 'Collaboratore rimosso',
        'already_invited' => 'C’è già un invito in attesa per questo indirizzo: puoi reinviarlo dall’elenco.',
        'pending_name' => 'Invito in attesa',
        'invited_on' => 'Invitato il',
        'resend' => 'Reinvia l’invito',
        'resend_confirm' => 'Parte un nuovo messaggio con un collegamento valido altri :days giorni; quello di prima smette di funzionare.',
        'resent' => 'Invito reinviato',
        'withdraw' => 'Ritira l’invito',
        'withdraw_confirm' => 'Il collegamento già spedito smetterà di funzionare. Potrai invitare di nuovo questo indirizzo quando vuoi.',
        'withdrawn' => 'Invito ritirato',
    ],

    'access' => [
        'title' => 'Il tuo accesso',
        'menu' => 'Il tuo accesso a questo locale',
        'lead' => 'Gestisci :venue come :role.',
        'leave' => 'Lascia il locale',
        'leave_confirm' => 'Non vedrai più il pannello di :venue e non riceverai più le sue email. Gli eventi che hai inserito restano. Per tornare servirà un nuovo invito.',
        'left' => 'Hai lasciato :venue',
        'last_owner' => 'Sei l’unico referente di questo locale: prima di lasciarlo scrivi alla redazione, che deciderà a chi passarlo.',
    ],

    'organizer_invitation' => [
        'subject' => ':organizer su :product: il tuo pannello è pronto',
        'intro' => 'La redazione ha accolto la richiesta per :organizer. Da :product puoi proporre le date dei tuoi eventi, in qualunque locale della città.',
    ],

    'invitation' => [
        'subject' => ':venue su :product',
        'greeting' => 'Ciao :name,',
        'intro' => 'Da adesso puoi pubblicare gli eventi di :venue su :product, come :role.',
        'password_hint' => 'Scegli una password per entrare: il collegamento vale un’ora.',
        'password_action' => 'Scegli la password',
        'ignore' => 'Se non ti aspettavi questo messaggio, puoi ignorarlo.',
        'open_action' => 'Apri il pannello',
        'invite_intro' => 'Ti hanno invitato a pubblicare gli eventi di :venue su :product, come :role.',
        'accept_hint' => 'Entra con il tuo account e accetta: finché non lo fai non vedi il pannello, non ricevi le email del locale e il locale non vede il tuo nome. Il collegamento vale :days giorni.',
        'accept_action' => 'Accetta l’invito',
        'decline_action' => 'Rifiuta',
        'decline_hint' => 'Dalla stessa pagina puoi anche rifiutare. Se non ti aspettavi questo messaggio, ignoralo: senza una tua risposta non cambia niente.',
        'password_accept_hint' => 'Scegli una password per accettare ed entrare: il collegamento vale un’ora, e se scade basta usare «password dimenticata» con questo indirizzo.',
        'page_title' => 'Invito in un locale',
        'page_lead' => 'Ti hanno invitato a gestire :venue come :role.',
        'page_consequences' => 'Se accetti entri nel pannello del locale, ricevi le sue email (per esempio i nuovi commenti) e chi lo gestisce vede il tuo nome. Potrai lasciarlo quando vuoi.',
        'declined' => 'Hai rifiutato l’invito di :venue.',
        'invalid' => 'Questo collegamento non è valido. Aprilo di nuovo dal messaggio, senza modificarlo.',
        'expired' => 'Questo invito è scaduto. Chiedi a chi te l’ha mandato di reinviarlo.',
        'wrong_account' => 'Questo invito è per un altro account (:email). Esci ed entra con quell’indirizzo, poi apri di nuovo il collegamento.',
        'gone' => 'Questo invito non è più valido: è stato ritirato, sostituito da uno più recente o già usato.',
    ],

    'notices' => [
        'action' => 'Avvisa chi ti segue',
        'heading' => 'Un avviso a chi segue il locale',
        'description' => 'Arriva una volta sola a chi segue il locale con le notifiche accese e compare sulla scheda pubblica fino alla data indicata (o per un mese). Uno ogni :days giorni.',
        'kind' => 'Tipo di avviso',
        'body' => 'Testo',
        'body_hint' => 'Breve e concreto: «Chiusi per ferie dal 10 al 24 agosto, si riapre il 25».',
        'until' => 'Valido fino al',
        'until_hint' => 'Facoltativo. Dopo questa data l’avviso sparisce dalla scheda.',
        'submit' => 'Pubblica l’avviso',
        'published' => 'Avviso pubblicato: in arrivo a :count persone.',
        'too_soon' => 'Hai già pubblicato un avviso negli ultimi :days giorni. Aspetta prima di inviarne un altro.',
        'last' => 'Ultimo avviso: :date (:kind).',
    ],

    'notifications' => [
        'published' => 'Evento pubblicato',
        'published_body' => 'È online: chi cerca cosa fare in città lo vede.',
        'sent_to_review' => 'Evento inviato alla redazione',
        'sent_to_review_body' => 'La redazione lo controlla e lo pubblica: ti arriva un avviso appena è online.',
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
            'url_help' => 'Incolla qui l\'indirizzo. Viene letto ogni ora e le tue date restano aggiornate.',
            'exclude' => 'Parole che tengono fuori una data',
            'exclude_help' => 'Le date il cui titolo contiene una di queste parole non vengono pubblicate. Servono a lasciare fuori le voci interne del tuo calendario, come le riunioni o le chiusure.',
            'exclude_placeholder' => 'Aggiungi una parola',
            'type' => 'Che cosa colleghi',
            'type_ics' => 'Un calendario (ICS)',
            'type_csv' => 'Un foglio di date (CSV)',
            'csv_help' => 'Un foglio con una riga per data e le intestazioni nella prima riga: titolo, data (o inizio), ora, fine, descrizione, link, luogo, id. Puoi incollare l\'indirizzo di un Google Sheet «pubblicato come CSV» oppure caricare il file.',
            'file' => 'Oppure carica il file CSV',
            'file_help' => 'Un file caricato si legge una volta; per tenerlo aggiornato ricaricalo, o usa un indirizzo.',
            'url_or_file' => 'Serve un indirizzo oppure un file.',
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

    'same_night' => [
        'title' => 'Stessa sera in zona',
        'hint' => 'Date già pubblicate da altri, entro :km km, che si sovrappongono a questo orario. È solo un avviso: la sera la scegli tu.',
        'distance' => 'a :m m',
        'same_category' => 'stessa categoria (:category)',
    ],
    'closures' => [
        'title' => 'Chiusure straordinarie e ferie',
        'hint' => 'Giorni in cui il locale resta chiuso anche se l’orario direbbe aperto. Sul sito e nell’app compare «Chiuso fino al…» e chi crea una data in quei giorni riceve un avviso. Le chiusure finite si tolgono da sole.',
        'add' => 'Aggiungi una chiusura', 'from' => 'Dal', 'to' => 'Al (compreso)', 'note' => 'Nota per il pubblico', 'note_placeholder' => 'Per esempio: ferie estive',
        'warning' => 'Il locale risulta chiuso quel giorno',
        'warning_body' => 'Chiusura dal :from al :to:note. Puoi salvare lo stesso, ma controlla la data o le chiusure del locale.',
    ],

    /*
     * L'elenco degli eventi in `/gestione` e `/organizza`: una card per
     * evento, con la locandina, la data che conta e lo stato in italiano.
     */
    /*
     * Il modulo rapido dell'evento: un blocco essenziale, tutto il resto in
     * sezioni facoltative chiuse.
     */
    'quick' => [
        'essentials' => 'L’essenziale',
        'essentials_hint' => 'Bastano questi campi per pubblicare. Tutto il resto è facoltativo e si aggiunge anche dopo.',
        'edit_essentials_hint' => 'Le date si cambiano più in basso, nella sezione «Date».',
        'when' => 'Quando',
        'facebook' => 'Hai già l’evento su Facebook?',
        'facebook_hint' => 'Incolla il link: titolo, data, locandina e descrizione si compilano da soli, poi controlli e pubblichi.',
        'more' => 'Altri dettagli',
        'more_hint' => 'Orario di fine, tag, biglietti online e link: facoltativi.',
        'where' => 'Si tiene altrove?',
        'publishing' => 'Pubblicazione',
        'preview' => 'Anteprima',
        'preview_hint' => 'Così compare negli elenchi del sito.',
        'preview_untitled' => 'Titolo dell’evento',
        'preview_no_date' => 'Scegli giorno e ora',
        'preview_free' => 'Ingresso libero',
        'save_draft' => 'Salva come bozza',
        'draft_saved' => 'Bozza salvata',
        'draft_saved_body' => 'La trovi fra le bozze dei tuoi eventi. Per pubblicarla completa data e categoria e premi «Pubblica».',
        'draft_needs_title' => 'Scrivi almeno il titolo per salvare la bozza.',
        'tickets_hint' => 'Prenotazioni e biglietti di inCittà per le singole date.',
        'other_sections_hint' => 'Facoltativo: aprilo solo se ti serve.',
    ],

    /*
     * I collegamenti di un evento verso le altre pagine del pannello: dalla
     * scheda, dalla riga dell'elenco e dal riepilogo.
     */
    'event_links' => [
        'edit' => 'Modifica',
        'view' => 'Apri la scheda',
        'statistics' => 'Statistiche',
        'social' => 'Grafiche social',
        'sponsor' => 'Sponsorizza',
        'tickets' => 'Biglietteria',
        'site' => 'Vedi sul sito',
        'heading' => 'Cosa puoi fare',
    ],

    'event_list' => [
        'next' => 'Prossima data: :date',
        'last' => 'Ultima data: :date',
        'past' => 'Passato',
        'venue' => 'Locale principale',
        'timing' => 'Quali eventi',
        'all' => 'Tutti',
        'upcoming' => 'Prossimi',
        'past_plural' => 'Passati',
        'drafts' => 'Bozze',
        'more_actions' => 'Altre azioni',
        'empty' => 'Nessun evento da mostrare',
    ],

    /*
     * La scheda di un evento nel pannello, in sola lettura: card impilate a
     * tutta larghezza, una per argomento.
     */
    'event_view' => [
        'hint' => 'Questa è la scheda in sola lettura. Per cambiare testi, locandina o una singola serata usa «Modifica».',
        'poster_alt' => 'Locandina di :title',
        'no_poster' => 'Nessuna locandina',
        'next_date' => 'Prossima data',
        'last_date' => 'Ultima data',
        'no_dates' => 'Ancora nessuna data',
        'statistics' => 'Statistiche',
        'info' => 'Informazioni',
        'info_description' => 'Quello che si legge sulla scheda pubblica.',
        'tags' => 'Tag',
        'price_range' => 'Prezzo',
        'price_from_to' => 'da :min a :max €',
        'price_from' => 'da :min €',
        'price_up_to' => 'fino a :max €',
        'description' => 'Descrizione',
        'no_description' => 'La descrizione è vuota: aggiungila da «Modifica», è la prima cosa che legge chi apre la scheda.',
        'dates' => 'Serate',
        'dates_description' => '{0} Nessuna serata inserita.|{1} Una serata.|[2,*] :count serate.',
        'upcoming_dates' => 'In programma',
        'past_dates' => 'Già passate',
        'past_date' => 'Passata',
        'ends' => 'fino alle :time',
        'ends_other_day' => 'fino al :date',
        'doors' => 'Porte alle :time',
        'capacity' => 'Posti liberi: :left su :total',
        'capacity_total' => 'Capienza: :total',
        'lineup' => 'Programma: :names',
    ],
];
