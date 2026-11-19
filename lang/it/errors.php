<?php

declare(strict_types=1);

/*
 * Le pagine d'errore del sito e i messaggi con cui un modulo torna indietro.
 *
 * Ogni pagina dice cosa è successo con parole di chi usa il sito, senza codici
 * da tecnico nel titolo, e offre una strada per proseguire. Il codice HTTP sta
 * nell'occhiello, per chi deve riferirlo a qualcuno.
 */
return [
    'eyebrow' => 'Errore :code',

    'actions' => [
        'back' => 'Torna indietro',
        'back_to_form' => 'Torna al modulo',
        'events' => 'Vai agli eventi',
        'home' => 'Vai alla prima pagina',
        'area' => 'Vai alla tua area',
        'login' => 'Accedi',
        'logout' => 'Esci e cambia account',
        'retry' => 'Riprova',
    ],

    'signed_in_as' => 'Hai effettuato l\'accesso come :email.',

    '403' => [
        'title' => 'Non puoi aprire questa pagina',
        'body' => 'Questa pagina è riservata a chi ha un accesso specifico. Se pensi di doverla vedere, entra con l\'account giusto.',
    ],
    '404' => [
        'title' => 'Questa pagina non esiste',
        'body' => 'Il link potrebbe essere vecchio, oppure l\'evento è stato rimosso. Puoi ritrovarlo fra gli eventi in programma.',
    ],
    '405' => [
        'title' => 'Questo indirizzo non si apre così',
        'body' => 'Il link che hai seguito porta a un\'azione che parte da un pulsante della pagina, non dalla barra degli indirizzi. Torna indietro e riprova da lì.',
    ],
    '413' => [
        'title' => 'Il file è troppo grande',
        'body' => 'Quello che hai inviato supera il peso consentito. Torna al modulo e scegli un file più leggero: per le foto basta una versione ridotta.',
    ],
    '419' => [
        'title' => 'La pagina è scaduta',
        'body' => 'È passato troppo tempo dall\'apertura del modulo, e per sicurezza non è stato inviato. Torna indietro, ricarica la pagina e invialo di nuovo.',
    ],
    '429' => [
        'title' => 'Troppi tentativi',
        'body' => 'Hai fatto molte richieste in poco tempo. Aspetta un momento e riprova.',
        'wait' => 'Puoi riprovare fra :seconds secondi.|Puoi riprovare fra :seconds secondi.',
    ],
    '500' => [
        'title' => 'Qualcosa non ha funzionato',
        'body' => 'C\'è stato un errore imprevisto e l\'ho registrato. Riprova fra qualche minuto.',
    ],
    '503' => [
        'title' => 'Il sito è in manutenzione',
        'body' => 'Sto aggiornando il sito: torna disponibile fra pochi minuti.',
    ],
    '4xx' => [
        'title' => 'Questa richiesta non è andata a buon fine',
        'body' => 'Qualcosa nella richiesta non torna. Torna indietro e riprova dalla pagina.',
    ],
    '5xx' => [
        'title' => 'Il sito non risponde come dovrebbe',
        'body' => 'C\'è stato un problema da questa parte. Riprova fra qualche minuto.',
    ],

    /* Un collegamento firmato (accesso, conferma dell'email) scaduto o già usato. */
    'link' => [
        'title' => 'Il collegamento è scaduto o è già stato usato',
        'body' => 'Per sicurezza i collegamenti che mando per email valgono poco tempo e una volta sola. Chiedine uno nuovo: arriva in pochi secondi.',
        'hint' => 'Se ti eri appena registrato, accedi con la tua password: ti rimando io il collegamento di conferma.',
        'request' => 'Chiedi un nuovo collegamento',
        'password' => 'Accedi con la password',
    ],

    /* Il messaggio con cui un modulo torna indietro dopo un 409 o un 422. */
    'form' => [
        'conflict' => 'Nel frattempo qualcosa è cambiato e la richiesta non è passata. Controlla i dati e invia di nuovo.',
        'unprocessable' => 'La richiesta non è stata accettata. Controlla i dati e riprova.',
    ],
];
