<?php

declare(strict_types=1);

/*
 * I moduli pubblici: proponi un evento, registra il tuo locale, segnala un
 * errore (§11.1, §14.6).
 *
 * Le chiavi sotto `fields` sono anche i nomi dei campi usati nei messaggi di
 * validazione: `attributes()` di ogni Form Request legge esattamente questo
 * elenco, così l'etichetta sopra al campo e quella dentro all'errore non
 * possono divergere.
 */
return [

    'required' => 'campo obbligatorio',
    'has_errors' => 'Controlla i campi segnalati: qualcosa non va.',
    'honeypot' => 'Lascia vuoto questo campo',
    'privacy_note' => 'Uso questi dati solo per valutare e pubblicare la segnalazione. Non finiscono in nessuna lista.',

    'submission' => [
        'title' => 'Proponi un evento',
        'lead' => 'Segnalami cosa succede a :city: la redazione controlla e pubblica. Bastano il titolo e un modo per ricontattarti.',
        'submit' => 'Invia la proposta',
        'received' => 'Proposta ricevuta. La redazione la valuta e, se pubblicabile, la mette online.',
        'venue_unknown' => 'Il locale non è in elenco',

        'fields' => [
            'title' => 'Titolo dell\'evento',
            'starts_at_hint' => 'Quando (anche approssimativo)',
            'venue_id' => 'Locale',
            'venue_hint' => 'Oppure scrivi dove si tiene',
            'raw_text' => 'Descrizione, programma, link',
            'contact_name' => 'Il tuo nome',
            'contact_email' => 'La tua email',
        ],

        'hints' => [
            'starts_at_hint' => 'Se non sai l\'ora esatta indica il giorno: la sistemo io.',
            'venue_hint' => 'Serve solo se il locale non è ancora in elenco.',
            'raw_text' => 'Incolla pure il testo del volantino o del post: lo riscrivo io.',
        ],
    ],

    'timing' => [
        'invalid' => 'Il modulo non è stato caricato per intero: ricarica la pagina e invia di nuovo.',
        'too_fast' => 'Hai inviato il modulo un istante dopo averlo aperto: ricontrolla i dati e invia di nuovo.',
        'expired' => 'Il modulo è rimasto aperto più di un giorno: ricontrolla i dati e invia di nuovo.',
    ],

    'claim' => [
        'title' => 'Rivendica il tuo locale',
        'lead' => ':venue è già nel catalogo. Se lo gestisci tu, chiedi le chiavi della sua pagina: verifico la richiesta e ti apro il pannello.',
        'submit' => 'Chiedi di gestire questo locale',
        'already_managed' => 'Questo locale ha già un referente. Se pensi sia un errore, scrivi alla redazione.',
        'back' => 'Torna a :venue',
        'venue_title' => 'Il locale',
        'venue_description' => 'La scheda che chiedi di gestire.',
        'open_venue' => 'Apri la scheda',
        'verify_title' => 'Come posso verificare che il locale è tuo?',
        'verify_description' => 'Scrivi il tuo ruolo, da quando lo gestisci e un modo per verificarlo: il sito o la pagina social dove compari, un numero del locale a cui risponderai, un documento che puoi mostrarmi.',
        'limit_reached' => 'Hai già una richiesta aperta per questo locale, o troppe richieste in attesa: aspetta la risposta prima di mandarne un\'altra.',
        'privacy_note' => 'Uso questi dati solo per verificare la richiesta e aprirti il pannello: nome, telefono ed email del referente non compaiono sul sito.',
        'fields' => [
            'message' => 'Ruolo, da quando e come verificarlo',
        ],
    ],

    'organizer_application' => [
        'title' => 'Registra la tua organizzazione',
        'lead' => 'Associazioni, collettivi, rassegne: se organizzi eventi senza una sede fissa, chiedi un pannello per proporre le tue date in qualunque locale di :city.',
        'submit' => 'Invia la richiesta',
        'organization_title' => 'L\'organizzazione',
        'organization_description' => 'Il nome con cui compari sul sito e cosa organizzi.',
        'hint_message' => 'Che tipo di eventi organizzi, dove e ogni quanto: diventa la presentazione dell\'organizzazione, che poi cambi dal pannello.',
        'fields' => [
            'venue_name' => 'Nome dell\'organizzazione',
            'website' => 'Sito o pagina social',
            'message' => 'Che cosa organizzi',
        ],
    ],

    'application' => [
        'title' => 'Registra il tuo locale',
        'lead' => 'Pubblica i tuoi eventi su :city e raggiungi chi sta già cercando qualcosa da fare stasera.',
        'submit' => 'Invia la richiesta',
        'type_unknown' => 'Non saprei',
        'limit_reached' => 'Hai già tre richieste in attesa: aspetta la risposta prima di mandarne un\'altra.',
        'verify_first' => 'Conferma prima la tua email: poi la richiesta entra in coda.',
        'confirmed' => 'Richiesta confermata: ora è in coda e la valuto al più presto.',
        'discarded' => 'Richiesta eliminata.',
        'privacy_note' => 'I contatti pubblici compaiono sulla scheda del locale; i tuoi (nome, ruolo, telefono, email) li uso solo per verificare la richiesta e non compaiono sul sito.',

        'sent' => [
            'queued' => 'Richiesta ricevuta: è in coda e la valuto al più presto. Ti ho mandato una ricevuta per email.',
            'verify' => 'Richiesta salvata. Conferma la tua email e la richiesta entra in coda.',
            'check_email' => 'Ti ho scritto: apri il collegamento che trovi nella posta per confermare l\'email. Solo allora la richiesta entra in coda.',
            'organizer' => 'Richiesta ricevuta. Ti ricontatto per verificare i dati e aprire il pannello dell\'organizzazione.',
        ],

        'sent_page' => [
            'queued_title' => 'Richiesta in coda',
            'verify_title' => 'Manca un passo: conferma la tua email',
            'check_email_title' => 'Controlla la posta',
            'organizer_title' => 'Richiesta ricevuta',
            'verify_action' => 'Mandami di nuovo il collegamento',
            'another' => 'Scrivi un\'altra richiesta',
            'events' => 'Torna agli eventi',
        ],

        'catalogue' => [
            'title' => 'Il tuo locale è già nel catalogo?',
            'description' => 'Molti locali ci sono già, inseriti dalla redazione. Se trovi il tuo, rivendicalo: non serve crearne un altro.',
            'label' => 'Nome del locale',
            'placeholder' => 'Per esempio: Caffè Pedrocchi',
            'submit' => 'Cerca',
            'results' => 'Locali trovati',
            'none' => 'Nessun locale con questo nome: compila il modulo qui sotto.',
            'claim' => 'Rivendica',
            'managed' => 'Ha già un referente',
            'short' => 'Scrivi almeno tre lettere.',
        ],

        'account_state' => [
            'awaiting_title' => 'Hai una richiesta da confermare',
            'awaiting_body' => 'La richiesta per :venue è stata scritta con il tuo indirizzo prima che entrassi. Se l\'hai mandata tu, confermala e la metto in coda; altrimenti eliminala.',
            'awaiting_unverified' => 'La richiesta per :venue entra in coda quando confermi la tua email.',
            'confirm' => 'Conferma e invia',
            'discard' => 'Elimina',
            'resend' => 'Mandami il collegamento',
            'in_review' => 'Richiesta per :venue in valutazione dal :date.',
        ],

        'sections' => [
            'login_title' => 'Hai già un account?',
            'login_description' => 'Accedi e torna qui: quello che hai scritto resta nel modulo.',
            'login_action' => 'Accedi e torna qui',
            'venue_title' => 'Il locale',
            'venue_description' => 'Come si chiama e che tipo di posto è.',
            'where_title' => 'Dove',
            'where_description' => 'L\'indirizzo serve a mettere il locale sulla mappa.',
            'public_title' => 'Contatti pubblici',
            'public_description' => 'Compaiono sulla scheda del locale: scrivi quelli che vuoi dare al pubblico.',
            'space_title' => 'Lo spazio',
            'space_description' => 'Facoltativo: aiuta chi cerca un posto adatto.',
            'image_title' => 'Un\'immagine',
            'image_description' => 'Facoltativa: diventa la copertina della scheda. Resta privata finché la richiesta non è approvata.',
            'image_hint' => 'JPG, PNG o WebP, almeno 400×300 pixel, al massimo 5 MB.',
            'contact_title' => 'Tu, referente',
            'contact_description' => 'I tuoi contatti restano privati: servono solo a verificare la richiesta.',
            'signed_in_as' => 'Mandi la richiesta come :email.',
            'account_title' => 'Il tuo account',
            'account_description' => 'Scegli una password: creo il tuo account e ti scrivo per confermare l\'email. La richiesta entra in coda dopo la conferma, e quando è approvata entri nel pannello con questo account.',
            'next_title' => 'Cosa succede dopo',
            'next' => [
                'confirm' => 'Confermi l\'email, se non l\'hai già fatto: la richiesta entra in coda.',
                'review' => 'Verifico i dati, di solito entro due giorni lavorativi, e se serve ti telefono.',
                'panel' => 'Quando approvo, entri nel pannello del locale con il tuo account e completi la scheda.',
                'publish' => 'La scheda va online quando è completa: da lì pubblichi i tuoi eventi.',
            ],
            'claim_next' => [
                'review' => 'Verifico che il locale sia tuo, con i dati che mi hai dato.',
                'panel' => 'Quando approvo, entri nel pannello del locale con il tuo account: la scheda resta online e da lì la aggiorni.',
            ],
            'organizer_next' => [
                'review' => 'Verifico i dati e se serve ti scrivo o ti telefono.',
                'panel' => 'Quando approvo, ti arriva il collegamento al pannello dell\'organizzazione.',
            ],
        ],

        'fields' => [
            'venue_name' => 'Nome del locale',
            'type' => 'Tipo di locale',
            'website' => 'Sito del locale',
            'address' => 'Indirizzo',
            'postal_code' => 'CAP',
            'municipality' => 'Comune',
            'public_email' => 'Email del locale',
            'public_phone' => 'Telefono del locale',
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
            'capacity' => 'Capienza (persone)',
            'description' => 'Presentazione del locale',
            'image' => 'Immagine',
            'contact_name' => 'Nome e cognome',
            'contact_role' => 'Ruolo',
            'contact_phone' => 'Il tuo telefono',
            'contact_email' => 'La tua email',
            'password' => 'Password',
            'password_confirmation' => 'Ripeti la password',
            'message' => 'Qualcosa da aggiungere',
            'form_started' => 'Modulo',
        ],

        'hints' => [
            'message' => 'Che tipo di eventi organizzi e ogni quanto. Lo leggo solo io.',
            'address' => 'Via e numero civico.',
            'description' => 'Due o tre righe per chi non conosce il posto. Si può cambiare dal pannello.',
            'password' => 'Almeno 8 caratteri.',
            'contact_role' => 'Per esempio: titolare, gestore, direttore artistico.',
            'public_phone' => 'Il numero a cui rispondi al pubblico, anche diverso dal tuo.',
            'social' => 'L\'indirizzo completo, con https://',
        ],
    ],

    'report' => [
        'title' => 'Segnala un errore',
        'heading' => 'Segnala un errore su :subject',
        'lead' => 'Se un orario, un prezzo o un indirizzo non tornano, dimmelo e lo correggo.',
        'submit' => 'Invia la segnalazione',
        'received' => 'Segnalazione ricevuta. Grazie: la controllo al più presto.',
        'reason_placeholder' => 'Scegli un motivo',

        'fields' => [
            'reason' => 'Cosa non va',
            'note' => 'Dettagli',
            'reporter_email' => 'La tua email',
        ],

        'hints' => [
            'note' => 'Più sei preciso, prima correggo.',
            'reporter_email' => 'Facoltativa: serve solo se devo chiederti un chiarimento.',
        ],
    ],

];
