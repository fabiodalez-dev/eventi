<?php

declare(strict_types=1);
use App\Models\WebPushSubscription;

/*
 * Web Push (§15.4, §15.6), il canale che D8 aveva escluso e D54 ha riaperto.
 *
 * Due scelte sole, ma nessuna delle due e' un dettaglio.
 *
 * **`model`.** Il pacchetto porta con se' una tabella `push_subscriptions` e
 * un modello che la legge. Qui non si usa: l'iscrizione di un browser e' gia'
 * un dispositivo, e i dispositivi stanno in `devices` da prima che il canale
 * esistesse (§15.8) — endpoint, chiavi, ultimo accesso, revoca. Adottare la
 * tabella del pacchetto significherebbe scrivere lo stesso fatto in due posti
 * e dover poi decidere, a ogni invio, quale dei due dice la verita'.
 * `WebPushSubscription` e' il modello del pacchetto puntato su `devices`.
 *
 * **`table_name` resta dichiarato ma non serve a nulla**: `WebPushSubscription`
 * fissa la propria tabella, quindi il costruttore del pacchetto non arriva mai
 * a leggere questa chiave. Sta qui perche' il pacchetto la pubblica e toglierla
 * renderebbe piu' difficile confrontare questo file con il suo originale.
 *
 * `database_connection` a `null` significa «quella predefinita». Il valore del
 * pacchetto e' `env('DB_CONNECTION')`, che in fase di test punterebbe altrove
 * rispetto alla connessione che il resto del codice sta usando.
 */
return [

    /*
     * Le chiavi VAPID identificano il mittente presso il servizio push del
     * browser. Si generano una volta con `php artisan webpush:vapid` e non si
     * cambiano piu': cambiarle invalida in un colpo solo tutte le iscrizioni
     * gia' concesse, perche' il browser lega l'iscrizione alla chiave pubblica
     * con cui e' stata chiesta.
     *
     * Senza chiavi il canale non e' attivo: `ChannelSelector` lo verifica e
     * ripiega sull'email, che e' cio' che accade in sviluppo e nei test.
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    'model' => WebPushSubscription::class,

    'table_name' => 'devices',

    'database_connection' => null,

    /*
     * Le opzioni del client HTTP che consegna le notifiche. L'hosting e'
     * condiviso (D5): l'invio parte dal worker della coda su database, e una
     * connessione lenta verso un servizio push non deve tenere fermo il giro.
     */
    'client_options' => [],

    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

];
