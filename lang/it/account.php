<?php

declare(strict_types=1);

/*
 * Account, salvataggi e follow (§15). Come ogni altro testo del prodotto, non
 * una sola di queste stringhe compare in una vista o in un controller: il nome
 * del prodotto arriva da config('app.name') e viene passato come :app (D10).
 */
return [

    'title' => 'Il mio profilo',

    'nav' => [
        'login' => 'Accedi',
        'account' => 'Il mio account',
        'feed' => 'Il mio feed',
        'profile' => 'Profilo',
        'admin' => 'Amministrazione',
        'venue' => 'Il mio locale',
        'logout' => 'Esci',
        'feed' => 'Il mio feed',
        'notifications' => 'Email e avvisi',
        'saved' => 'Salvati',
        'profile' => 'Profilo',
        'login' => 'Accedi',
        'register' => 'Crea un account',
        'logout' => 'Esci',
    ],

    'save' => [
        'action' => 'Salva questa data',
        'saved' => 'Data salvata',
        'remove' => 'Togli dai salvati',
        'all_dates' => 'Salva tutte le date',
        'choose_dates' => 'Scegli le date da salvare',
        'follow_series' => 'Segui questo evento',
        'following_series' => 'Segui già questo evento',
        'follow_series_hint' => 'Ogni nuova data di questa rassegna finirà nei tuoi salvati.',
        'stored' => 'Data salvata.',
        'removed' => 'Data tolta dai salvati.',
        'not_savable' => 'Questa data non è più disponibile.',
        'merged' => '{0} Non c\'era nulla da recuperare.|{1} Ho ritrovato una data e l\'ho messa in agenda.|[2,*] Ho ritrovato :count date e le ho messe in agenda.',
    ],

    /*
     * Il dialogo che compare dopo il primo salvataggio da anonimo.
     *
     * Il titolo dice per prima cosa che **e' fatta**: chi ha appena premuto il
     * cuore deve sapere che il gesto e' andato a segno, prima di leggere una
     * proposta. Un invito che si apre senza confermare l'azione sembra un
     * ostacolo messo davanti a quello che si stava facendo.
     */
    'prompt' => [
        'title' => 'Salvato su questo dispositivo',
        'body' => 'Resta qui, in questo browser: se cambi telefono non lo ritrovi. Con un account lo ritrovi ovunque e ti avviso prima che cominci.',
        'action' => 'Crea un account',
        'login' => 'Ho già un account',
        'dismiss' => 'Non adesso',
    ],

    'follow' => [
        'organizer' => 'Segui questo organizzatore',
        'notify_organizer' => 'Includi questo organizzatore nei riepiloghi',
        'save_notifications' => 'Salva preferenza notifiche',
        'organizer_hint' => 'Seguirlo alimenta il feed. I riepiloghi rispettano anche le preferenze generali delle notifiche.',
        'venue' => 'Segui questo locale',
        'tag' => 'Segui questo tag',
        'category' => 'Segui questa categoria',
        'following' => 'Lo segui già',
        'stop' => 'Smetti di seguire',
        'stored' => 'Da adesso lo trovi nel tuo feed.',
        'removed' => 'Non lo segui più.',
        'hint' => 'I locali, i tag e le categorie che segui alimentano il tuo feed. I promemoria arrivano solo per le date che salvi.',
    ],

    'feed' => [
        'title' => 'Il mio feed',
        'lead' => 'Le prossime date di ciò che segui.',
        'saved_badge' => 'In agenda',
        'onboarding_title' => 'Comincia da qui',
        'onboarding_lead' => 'Segui qualche locale o categoria: le loro prossime date compariranno qui.',
        'onboarding_venues' => 'I locali più attivi',
        'onboarding_categories' => 'Le categorie principali',
    ],

    'saved' => [
        /*
         * Il pannello che si apre dall'icona in testata. Esiste perché chi
         * salva senza un account non aveva NESSUN posto dove rivedere le
         * proprie date: la pagina dei salvataggi chiede l'accesso, e su
         * schermo largo non c'era nemmeno un collegamento.
         */
        'panel_open' => 'Le tue date salvate',
        'panel_count' => ':count data salvata|:count date salvate',
        'panel_all' => 'Apri la pagina dei salvataggi',
        'panel_more' => 'Vedi tutte e :count',
        'panel_guest_hint' => 'Sono salvate in questo browser. Con un account le ritrovi su ogni dispositivo e ti avviso prima che comincino.',
        'panel_guest_action' => 'Accedi o crea un account',
        'panel_close' => 'Chiudi',
        'panel_loading' => 'Carico le tue date…',
        'panel_error' => 'Non sono riuscito a caricare le tue date. Riprova, oppure apri la pagina dei salvataggi.',
        'open_event' => 'Apri evento',
        'login_required' => 'Accedi o crea un account per aprire i tuoi Salvati. Dopo l’accesso tornerai qui; gli eventi salvati su questo dispositivo non vengono cancellati.',
        'past_tab' => 'Passati',
        'title' => 'I miei salvataggi',
        'lead' => 'Le date che hai messo in agenda.',
        'empty_title' => 'Non hai ancora salvato nessuna data',
        'empty_body' => 'Il cuore su ogni evento la mette qui, e prima che cominci ti avviso.',
        'past' => 'Date passate',
        'show_past' => 'Mostra anche le date passate',
        'show_upcoming' => 'Mostra solo le prossime date',
        'view' => 'Vista dei salvataggi',
        'list_view' => 'Lista',
        'calendar_view' => 'Calendario',
        'previous_month' => 'Mese precedente',
        'next_month' => 'Mese successivo',
        'month_events' => 'Eventi salvati di :month',
        'no_events_in_month' => 'Non hai eventi salvati in questo mese.',
        'saved_on_day' => ':count evento salvato|:count eventi salvati',
    ],

    'register' => [
        'first_name' => 'Nome (facoltativo)',
        'last_name' => 'Cognome (facoltativo)',
        'title' => 'Crea un account',
        'lead' => 'Serve solo per i promemoria e per ritrovare i salvataggi su ogni dispositivo. Nient\'altro.',
        'name' => 'Nome (facoltativo)',
        'email' => 'Email',
        'password' => 'Password',
        'marketing' => 'Voglio ricevere la newsletter «Questo weekend»',
        'marketing_hint' => 'È una cosa a parte dai promemoria: puoi disdirla quando vuoi.',
        'submit' => 'Crea l\'account',
        'have_account' => 'Hai già un account?',
        'done' => 'Account creato. Ti ho mandato un\'email per confermare l\'indirizzo.',
    ],

    'login' => [
        'title' => 'Accedi',
        'lead' => 'Con la password, oppure con un collegamento via email.',
        'email' => 'Email',
        'password' => 'Password',
        'remember' => 'Resta collegato',
        'submit' => 'Accedi',
        'no_account' => 'Non hai un account?',
        'failed' => 'Email o password non corrispondono.',
        'done' => 'Bentornato.',
        'logged_out' => 'Sessione chiusa.',
    ],

    /*
     * Reimpostare la password (§15.2).
     *
     * «Se quell'indirizzo è registrato» non è un giro di parole: la risposta
     * dev'essere la stessa che l'email esista o no, altrimenti basta provarne
     * una per sapere chi è iscritto. Il testo lo dice apertamente invece di
     * fingere, perché una frase ambigua fa solo credere a un guasto.
     */
    'forgot' => [
        'title' => 'Password dimenticata',
        'lead' => 'Scrivi il tuo indirizzo: se è registrato, ti mando un collegamento per sceglierne una nuova.',
        'submit' => 'Mandami il collegamento',
        'sent' => 'Se quell\'indirizzo è registrato, il collegamento è partito. Controlla anche la posta indesiderata.',
        'from_login' => 'Password dimenticata?',
        'prefer_magic' => 'Oppure entra senza password, con un collegamento usa e getta.',
    ],

    'reset' => [
        'title' => 'Scegli una nuova password',
        'lead' => 'Vale una volta sola. Appena confermi, esci da tutti i dispositivi collegati.',
        'password' => 'Nuova password',
        'confirm' => 'Ripetila',
        'submit' => 'Salva la nuova password',
        'done' => 'Fatto. Ora puoi entrare con la password nuova.',
        'failed' => 'Questo collegamento non vale più: può essere scaduto, già usato, oppure appartenere a un altro indirizzo. Chiedine un altro.',
    ],

    'magic' => [
        'title' => 'Accedi senza password',
        'lead' => 'Ti mando un collegamento valido :minutes minuti: un clic e sei dentro.',
        'submit' => 'Mandami il collegamento',
        'sent' => 'Se l\'indirizzo è registrato, fra poco riceverai il collegamento per entrare.',
        'expired' => 'Il collegamento non è più valido. Chiedine un altro.',
        'with_password' => 'Preferisco usare la password',
    ],

    'verify' => [
        'step' => 'Un ultimo passo',
        'instructions' => 'Apri la mail che abbiamo inviato a questo indirizzo e premi il pulsante di conferma.',
        'benefits' => 'Dopo la conferma puoi commentare, lasciare reazioni e ricevere promemoria. Nel frattempo puoi già salvare gli eventi.',
        'help' => 'Non trovi la mail? Controlla anche la cartella spam oppure richiedi un nuovo collegamento.',
        'explore' => 'Esplora gli eventi',
        'title' => 'Conferma il tuo indirizzo',
        'lead' => 'Ti ho mandato un\'email a :email. Apri il collegamento per confermare il tuo indirizzo: potrai commentare, lasciare reazioni e ricevere promemoria. Puoi già salvare le date.',
        'resend' => 'Mandami di nuovo l\'email',
        'sent' => 'Ti ho mandato una nuova email di conferma.',
        'done' => 'Indirizzo confermato: ora puoi commentare, lasciare reazioni e ricevere promemoria.',
        'confirmed' => 'L\'indirizzo è stato confermato.',
        'failed' => 'Il collegamento di conferma non è valido o è scaduto.',
        'pending' => 'Indirizzo da confermare',
    ],

    'profile' => [
        'title' => 'Il mio profilo',
        'lead' => 'Di te tengo solo questo.',
        'name' => 'Nome (facoltativo)',
        'email' => 'Email',
        'timezone' => 'Fuso orario',
        'locale' => 'Lingua',
        'submit' => 'Salva le modifiche',
        'saved' => 'Profilo aggiornato.',
        'notifications_title' => 'Notifiche',
        'notifications_lead' => 'Gli avvisi di annullamento di una data che hai salvato arrivano sempre: un promemoria per una serata che non c\'è più è peggio di nessun promemoria.',
        'reminders' => 'Promemoria prima degli eventi salvati',
        'sold_out' => 'Avvisami se un evento salvato va esaurito',
        'venue_digest' => 'Nuovi eventi e riepilogo settimanale dei locali che seguo',
        'daily_digest' => 'Riepilogo giornaliero «stasera nei tuoi generi»',
        'tonight' => 'Proposta della sera: poche date che cominciano stasera',
        'tonight_hint' => 'Arriva solo nei giorni che scegli. Se hai acconsentito a salvare la tua posizione approssimata, le proposte partono da lì; altrimenti dalla tua città. Sotto due proposte utili non ti arriva niente.',
        'tonight_time' => 'A che ora',
        'tonight_days' => 'In quali giorni',
        'weekdays' => [1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica'],
        'quiet_hours' => 'Ore di silenzio',
        'quiet_hours_hint' => 'In queste ore non ti arriva nulla: quello che è previsto aspetta la mattina. Gli avvisi di annullamento fanno eccezione e arrivano comunque.',
        'quiet_from' => 'Dalle',
        'quiet_to' => 'Alle',
        'quiet_off' => 'Nessuna ora di silenzio: mandami tutto anche di notte',
        'marketing' => 'Newsletter «Questo weekend»',
        'export' => 'Scarica i miei dati',
        'export_hint' => 'Un file con tutto ciò che mi hai dato: profilo, salvataggi, follow.',
        'delete_title' => 'Cancella l\'account',
        'delete_body' => 'Spariscono salvataggi, follow e promemoria programmati. Non si torna indietro.',
        'delete_confirm' => 'Scrivi CANCELLA per confermare',
        'delete_keyword' => 'CANCELLA',
        'delete_submit' => 'Cancella il mio account',
        'deleted' => 'Account cancellato.',
    ],

    'mail' => [
        'verify' => [
            'subject' => 'Conferma il tuo indirizzo su :product',
            'intro' => 'Conferma il tuo indirizzo per commentare gli eventi, lasciare reazioni e ricevere promemoria.',
            'action' => 'Conferma l\'indirizzo',
            'expires' => 'Il collegamento scade fra :minutes minuti.',
            'ignore' => 'Se non ti sei registrato tu, puoi ignorare questo messaggio.',
        ],
        'magic' => [
            'subject' => 'Il tuo collegamento di accesso a :product',
            'intro' => 'Ecco il collegamento per entrare senza password.',
            'action' => 'Entra',
            'expires' => 'Il collegamento scade fra :minutes minuti e vale una volta sola.',
            'ignore' => 'Se non l\'hai chiesto tu, puoi ignorare questo messaggio: nessuno è entrato.',
        ],
    ],

    'api' => [
        'saved' => 'Data salvata.',
        'unsaved' => 'Data tolta dai salvati.',
        'not_savable' => 'Questa data non si può salvare: è passata o non è pubblica.',
        'merged' => 'Salvataggi migrati sull\'account.',
        'unfollowed' => 'Non lo segui più.',
        'device_revoked' => 'Dispositivo revocato.',
        'session_revoked' => 'Sessione revocata.',
        'notifications_read' => 'Notifiche segnate come lette.',
        'deleted' => 'Account cancellato.',
        'magic_link_sent' => 'Se l\'indirizzo è registrato, riceverai un collegamento per entrare.',
        'email_verified' => 'Indirizzo confermato.',
    ],

];
