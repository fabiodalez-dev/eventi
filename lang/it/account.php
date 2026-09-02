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
        'feed' => 'Il mio feed',
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
        'title' => 'I miei salvataggi',
        'lead' => 'Le date che hai messo in agenda.',
        'empty_title' => 'Non hai ancora salvato nessuna data',
        'empty_body' => 'Il cuore su ogni evento la mette qui, e prima che cominci ti avviso.',
        'past' => 'Date passate',
        'show_past' => 'Mostra anche le date passate',
        'show_upcoming' => 'Mostra solo le prossime date',
    ],

    'register' => [
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

    'magic' => [
        'title' => 'Accedi senza password',
        'lead' => 'Ti mando un collegamento valido :minutes minuti: un clic e sei dentro.',
        'submit' => 'Mandami il collegamento',
        'sent' => 'Se l\'indirizzo è registrato, fra poco riceverai il collegamento per entrare.',
        'expired' => 'Il collegamento non è più valido. Chiedine un altro.',
        'with_password' => 'Preferisco usare la password',
    ],

    'verify' => [
        'title' => 'Conferma il tuo indirizzo',
        'lead' => 'Ti ho mandato un\'email a :email. Finché non la confermi puoi salvare le date, ma non posso mandarti promemoria.',
        'resend' => 'Mandami di nuovo l\'email',
        'sent' => 'Ti ho mandato una nuova email di conferma.',
        'done' => 'Indirizzo confermato: da adesso posso avvisarti prima degli eventi che salvi.',
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
        'venue_digest' => 'Riepilogo settimanale dei locali che seguo',
        'daily_digest' => 'Riepilogo giornaliero «stasera nei tuoi generi»',
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
            'intro' => 'Confermando l\'indirizzo potrò avvisarti prima degli eventi che salvi.',
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
        'deleted' => 'Account cancellato.',
        'magic_link_sent' => 'Se l\'indirizzo è registrato, riceverai un collegamento per entrare.',
        'email_verified' => 'Indirizzo confermato.',
    ],

];
