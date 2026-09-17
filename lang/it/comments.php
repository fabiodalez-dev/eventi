<?php

declare(strict_types=1);

return [
    'title' => 'Commenti',
    'singular' => 'Commento',
    'plural' => 'Commenti agli eventi',

    'count' => '{0}Nessun commento|{1}Un commento|[2,*]:count commenti',
    'empty' => 'Non c’è ancora nessun commento. Scrivi tu il primo.',

    'write' => 'Scrivi un commento',
    'body' => 'Il tuo commento',
    'placeholder' => 'Una domanda pratica, un consiglio, un ricordo…',
    'guidance' => 'Da 3 a 2.000 caratteri. Il commento compare subito, col tuo nome.',
    'submit' => 'Pubblica',
    'submitted' => 'Commento pubblicato.',

    'reply' => 'Rispondi',
    'reply_to' => 'Rispondi a :name',
    'reply_submit' => 'Pubblica la risposta',
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
        'count' => '{0}Nessuna reazione|{1}Una reazione|[2,*]:count reazioni',
    ],

    'status' => [
        'published' => 'Pubblicato',
        'hidden' => 'Nascosto',
    ],

    /* Quello che vede l’autore quando il suo commento è stato nascosto. */
    'hidden_notice' => 'Questo commento è stato nascosto da chi organizza l’evento.',

    'moderation' => [
        'hide' => 'Nascondi',
        'restore' => 'Rimetti in vista',
        'note' => 'Motivo (resta nel pannello, non è pubblico)',
        'hidden' => 'Commento nascosto.',
        'restored' => 'Commento di nuovo visibile.',
        'changed' => 'Il commento è cambiato da quando l’hai aperto. Ricarica e rileggilo prima di decidere.',
        'by' => 'Nascosto da :name il :date',
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
    ],
];
