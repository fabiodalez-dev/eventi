<?php

declare(strict_types=1);

return [
    'anonymous' => 'Utente',
    'title' => 'Commenti',
    'preference' => 'Risposte e reazioni ai miei commenti',
    'all' => 'Tutti i commenti',
    'more_replies' => 'Altre risposte',
    'more_replies_count' => '{1}Mostra un’altra risposta|[2,*]Mostra altre :count risposte',
    'previous_replies' => 'Risposte precedenti',
    'thread_nav' => 'Torna a tutti i commenti',
    'replies_nav' => 'Pagine delle risposte',
    'pages_nav' => 'Pagine dei commenti',
    'singular' => 'Commento',
    'plural' => 'Commenti agli eventi',

    'count' => '{0}Nessun commento|{1}Un commento|[2,*]:count commenti',
    'empty' => 'Non c’è ancora nessun commento. Scrivi tu il primo.',
    'unavailable' => 'Il commento che cercavi non è più disponibile.',

    'verify_title' => 'Manca un passo per commentare',
    'verify_required' => 'Conferma il tuo indirizzo email per commentare e lasciare reazioni.',
    'write' => 'Scrivi un commento',
    'body' => 'Il tuo commento',
    'placeholder' => 'Una domanda pratica, un consiglio, un ricordo…',
    'guidance' => 'Da 3 a 2.000 caratteri. Non inserire dati personali, contatti privati o messaggi promozionali.',
    'submit' => 'Pubblica',
    'submitted' => 'Commento pubblicato.',

    'reply' => 'Rispondi',
    'reply_to' => 'Rispondi a :name',
    'reply_to_sr' => 'a :name',
    'reply_placeholder' => 'Scrivi una risposta…',
    'reply_submit' => 'Rispondi',
    'replies' => '{1}Una risposta|[2,*]:count risposte',
    'cancel' => 'Annulla',

    'delete' => 'Elimina',
    'delete_confirm' => 'Vuoi eliminare questo commento? Spariscono anche le risposte.',
    'deleted' => 'Commento eliminato.',

    'report' => 'Segnala',

    'previous' => 'Commenti precedenti',
    'next' => 'Altri commenti',

    /* Le tre reazioni. Vedi App\Enums\EventCommentReactionType. */
    'reactions' => [
        'like' => 'Mi piace',
        'love' => 'Adoro',
        'useful' => 'Utile',
        'label' => 'reazioni',
        'group' => 'Reazioni al commento di :name',
        'count' => '{0}Nessuna reazione|{1}Una reazione|[2,*]:count reazioni',
    ],

    'status' => [
        'published' => 'Pubblicato',
        'hidden' => 'Nascosto',
    ],

    /* Quello che vede l’autore quando il suo commento è stato nascosto. */
    'hidden_notice' => 'Questo commento è stato nascosto dalla moderazione.',

    'moderation' => [
        'details' => 'Leggi e verifica',
        'event' => 'Evento',
        'body' => 'Testo del commento',
        'date' => 'Ultima moderazione',
        'hide' => 'Nascondi',
        'restore' => 'Rimetti in vista',
        'note' => 'Motivo (resta nel pannello, non è pubblico)',
        'hidden' => 'Commento nascosto.',
        'restored' => 'Commento di nuovo visibile.',
        'changed' => 'Il commento è cambiato da quando l’hai aperto. Ricarica e rileggilo prima di decidere.',
        'by' => 'Nascosto da :name il :date',
    ],

    'filter' => [
        'personal_data' => 'Rimuovi i dati personali o sensibili (email, telefono, indirizzo privato o codici) prima di pubblicare.',
        'spam' => 'Il testo contiene ripetizioni o contenuti promozionali sospetti. Riformula il commento.',
        'duplicate' => 'Hai già inviato questo testo di recente. Attendi prima di ripubblicarlo.',
    ],

    'notifications' => [
        'reply' => ':name ha risposto a un tuo commento',
        'reaction' => 'A :name piace un tuo commento',
        'moderated' => 'Un tuo commento è stato nascosto',
        'new' => ':name ha commentato un tuo evento',
    ],

    /* Il modale che chiede l’iscrizione. */
    'join' => [
        'title' => 'Serve un account',
        'body' => 'Per commentare un evento e per lasciare una reazione bisogna avere un profilo su inCittà. Ci vuole meno di un minuto.',
        'register' => 'Crea un profilo',
        'login' => 'Ho già un account',
        'close' => 'Chiudi',
        'prompt' => 'Accedi per commentare',
        'lead' => 'Partecipa alla conversazione',
        'hint' => 'Fai una domanda, rispondi agli altri, lascia una reazione. Serve un profilo, che si crea in meno di un minuto.',
    ],
];
