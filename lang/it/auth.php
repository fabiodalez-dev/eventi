<?php

declare(strict_types=1);

/*
 * Accesso, login unico e pannelli.
 *
 * Le prime tre voci sono quelle che Laravel e i suoi pacchetti cercano da
 * sé (`auth.failed`, `auth.password`, `auth.throttle`): senza questo file
 * uscivano in inglese. Il resto è il testo delle pagine di accesso e dei
 * pannelli: una porta sola, `/accedi`, per tutti.
 */
return [
    'failed' => 'Email o password non corrispondono.',
    'password' => 'La password non è corretta.',
    'throttle' => 'Troppi tentativi di accesso. Riprova fra :seconds secondi.',

    /*
     * I due rifiuti che un modulo di accesso può ricevere prima ancora di
     * guardare i dati: la sessione scaduta (419) e il limite dei tentativi
     * (429). Si torna al modulo, con quello che c'era scritto, e questo
     * messaggio sopra.
     */
    'form' => [
        'title' => 'Il modulo non è stato inviato',
        'expired' => 'La pagina è rimasta aperta a lungo e la sessione è scaduta. Riprova: i dati che avevi scritto sono ancora qui.',
        'throttled' => 'Troppi tentativi in poco tempo. Riprova fra un secondo.|Troppi tentativi in poco tempo. Riprova fra :seconds secondi.',
        'throttled_minutes' => 'Troppi tentativi in poco tempo. Riprova fra un minuto.|Troppi tentativi in poco tempo. Riprova fra :minutes minuti.',
    ],

    'panel' => [
        /*
         * Il 403 di chi è collegato ma non può aprire un pannello. La pagina
         * d'errore lo mostra sotto il titolo, con «Vai alla tua area» ed
         * «Esci e cambia account».
         */
        'forbidden' => [
            'admin' => 'Quest\'area è riservata alla redazione. Il tuo account non ne fa parte.',
            'venue' => 'Non risulti tra i gestori di un locale attivo. Se ti hanno invitato, apri il collegamento dell\'invito con questo account.',
            'organizer' => 'Non risulti tra i gestori di un organizzatore attivo. Se ti hanno invitato, apri il collegamento dell\'invito con questo account.',
        ],

        /* Il riquadro di `/accedi` quando la pagina da cui si arriva è un pannello. */
        'intended' => [
            'admin' => 'Stai entrando nella redazione',
            'venue' => 'Stai entrando nel pannello del locale',
            'organizer' => 'Stai entrando nel pannello dell\'organizzatore',
            'body' => 'Accedi con l\'account con cui ti hanno dato accesso: subito dopo torni alla pagina che stavi aprendo.',
        ],
    ],

    'login' => [
        'card_title' => 'Entra con la password',
        'card_description' => 'Un solo accesso per tutto: profilo, biglietti, pannello del locale e redazione.',
        'forgot' => 'Password dimenticata?',
        'magic' => 'Entra senza password',
        'register_title' => 'Non hai un account?',
        'register_description' => 'Ti serve solo per i promemoria e per ritrovare i salvataggi su ogni dispositivo.',
        'register_action' => 'Crea un account',
        'claim_pending' => 'Prima conferma l\'indirizzo: ti ho appena scritto un collegamento per entrare. Aprilo da questo browser e la tua password resterà valida.',
    ],

    'magic' => [
        'card_title' => 'Ricevi il collegamento',
        'card_description' => 'Scrivi il tuo indirizzo: se è registrato ti arriva un messaggio con un pulsante per entrare.',
        'with_password' => 'Entra con la password',
        'confirm_card_title' => 'Conferma l\'accesso',
        'confirm_card_description' => 'Hai aperto il collegamento in un browser diverso da quello che l\'ha chiesto: entri solo se premi il pulsante.',
        'confirm_cancel' => 'Non sono io',
    ],

    'forgot' => [
        'card_title' => 'Ricevi il collegamento',
        'card_description' => 'Il messaggio arriva solo se l\'indirizzo è registrato. Controlla anche la posta indesiderata.',
        'back_login' => 'Torna all\'accesso',
    ],

    'reset' => [
        'card_title' => 'La nuova password',
        'card_description' => 'Almeno 8 caratteri. Dopo il salvataggio entri con questa da ogni dispositivo.',
        'for_email' => 'Per l\'account',
        'request_again' => 'Chiedi un nuovo collegamento',
    ],

    'register' => [
        'card_title' => 'I tuoi dati',
        'card_description' => 'Nome e cognome sono facoltativi. All\'indirizzo arriva un messaggio per confermarlo.',
        'password_confirmation' => 'Ripeti la password',
        'have_account' => 'Hai già un account?',
        'login_action' => 'Accedi',
        'sent_lead' => 'Ti ho scritto all\'indirizzo che hai indicato: apri il messaggio per entrare. Il collegamento vale :minutes minuti e conferma anche l\'indirizzo.',
        'sent_card_title' => 'Cosa fare adesso',
        'sent_home' => 'Torna agli eventi',
    ],

    'verify' => [
        'eyebrow' => 'Un ultimo passo',
        'card_title' => 'Indirizzo da confermare',
        'instructions' => 'Apri il messaggio che ti ho mandato a questo indirizzo e premi il pulsante di conferma.',
        'help_title' => 'Il messaggio non arriva?',
        'app_card_title' => 'Apri l\'app',
        'app_card_description' => 'Se l\'app non si apre, puoi continuare dal sito.',
    ],

    'invitation' => [
        'card_title' => 'Cosa cambia se accetti',
        'unavailable_title' => 'Invito non disponibile',
        'home' => 'Torna agli eventi',
    ],

    /*
     * La pagina di ripiego del collegamento dell'app (`/app/auth/magic`):
     * autonoma, senza testata del sito, perché nell'indirizzo c'è il gettone.
     */
    'mobile_magic' => [
        'title' => 'Accesso all\'app',
        'heading' => 'Completa l\'accesso nell\'app',
        'lead' => 'Apri questo collegamento sul telefono da cui ti sei registrato o hai chiesto l\'accesso, con l\'app aggiornata.',
        'open' => 'Apri nell\'app',
        'fallback' => 'Se l\'app non si apre, puoi entrare dal sito chiedendo un collegamento di accesso.',
        'site' => 'Entra dal sito',
        'home' => 'Torna agli eventi',
    ],
];
