<?php

declare(strict_types=1);

return [

    /*
     * Messaggi di errore dell'API (§13.6). Il `code` è per le macchine e non
     * si traduce; il `message` è per chi legge il log o la schermata di
     * errore dell'app, e sta qui.
     */
    'errors' => [
        'validation_failed' => 'Alcuni dati non sono validi.',
        'unauthenticated' => 'Serve un accesso per questa richiesta.',
        'invalid_credentials' => 'Email o password non corrispondono.',
        'forbidden' => 'Questa richiesta non è consentita.',
        'not_found' => 'La risorsa richiesta non esiste.',
        'city_not_found' => 'Nessuna città attiva corrisponde a questa richiesta.',
        'conflict' => 'La richiesta è in conflitto con lo stato attuale della risorsa.',
        'report_already_pending' => 'Una segnalazione uguale è già in attesa di revisione.',
        'invalid_cursor' => 'Il cursore di paginazione non è leggibile.',
        'invalid_token' => 'Il collegamento non è valido o è scaduto.',
        'invalid_request' => 'La richiesta non è nella forma attesa.',
        'invalid_idempotency_key' => 'La chiave di idempotenza non è valida.',
        'idempotency_conflict' => 'La stessa chiave di idempotenza è già stata usata con dati diversi.',
        'rate_limited' => 'Troppe richieste: riprova fra poco.',
        'server_error' => 'Errore interno del server.',
    ],

    'auth' => [
        'registered' => 'Registrazione completata.',
        'logged_in' => 'Accesso effettuato.',
        'logged_out' => 'Sessione chiusa.',
        'password_link_sent' => 'Se l\'indirizzo è registrato, riceverai un messaggio con le istruzioni.',
        'password_reset' => 'Password aggiornata.',
        'password_reset_failed' => 'Il collegamento per reimpostare la password non è più valido.',
        'token_name' => 'Applicazione',
    ],

    'password' => [
        'subject' => 'Reimposta la password di :product',
        'intro' => 'Hai chiesto di reimpostare la password del tuo account.',
        'action' => 'Scegli una nuova password',
        'expires' => 'Il collegamento scade fra :minutes minuti.',
        'ignore' => 'Se non sei stato tu, puoi ignorare questo messaggio: la password resta quella di prima.',
    ],

    'submissions' => [
        'received' => 'Proposta ricevuta: la redazione la valuterà prima della pubblicazione.',
    ],

    'reports' => [
        'received' => 'Segnalazione ricevuta.',
    ],

    'legal' => [
        'terms' => 'Termini di servizio',
        'privacy' => 'Informativa sulla privacy',
    ],

    'docs' => [
        'title' => 'API pubblica',
        'description' => 'Interfaccia pubblica della piattaforma eventi: occorrenze, locali, calendario, mappa e ricerca. Le date sono ISO 8601 con offset e portano sempre la giornata evento (business_date). La paginazione è a cursore.',
    ],

];
