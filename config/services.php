<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Cloudflare Turnstile sui moduli pubblici (§14.7).
     *
     * Le due chiavi vuote **spengono** la protezione: nessun riquadro nel
     * modulo, nessuna regola in validazione. È voluto — sviluppo, test e CI
     * non devono dipendere da un servizio esterno raggiungibile, e un
     * ambiente configurato a metà respingerebbe ogni invio.
     */
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY', ''),
        'secret_key' => env('TURNSTILE_SECRET_KEY', ''),

        /*
         * I domini da cui accettiamo un gettone risolto.
         *
         * **Perche' serve, visto che la chiave e' nostra.** La site key e'
         * pubblica per costruzione: sta nell'HTML di ogni pagina. Chiunque
         * puo' copiare quel markup su un dominio proprio, far risolvere il
         * widget — da persone vere, o da un servizio che lo fa a pagamento —
         * e spedire i gettoni al NOSTRO endpoint. Sono gettoni autentici:
         * `success` risponde `true`, e senza questo controllo passano.
         *
         * Cloudflare dice da quale host e' stato risolto (`hostname` nella
         * risposta), e qui si dichiara quali accettiamo.
         *
         * **Vuoto significa «quello di `APP_URL`»**, che e' quasi sempre la
         * risposta giusta e soprattutto **segue il dominio quando cambia** —
         * e questo dominio e' provvisorio (§20.1). Scriverlo a mano qui
         * significherebbe che il giorno del trasloco i moduli cominciano a
         * rifiutare tutti senza un errore che lo spieghi.
         *
         * Si elencano a mano solo i casi con piu' domini veri: un'anteprima,
         * un secondo nome che punta allo stesso sito.
         */
        'hostnames' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TURNSTILE_HOSTNAMES', '')),
        ))),

        /*
         * Secondi di attesa per la risposta di Cloudflare. Breve di proposito:
         * un modulo pubblico che resta fermo perché un terzo ci ripensa è un
         * disservizio nostro.
         */
        'timeout' => /*
         * `(int)` non e' zelo: `env()` restituisce sempre stringhe, e
         * `config()->integer()` — che e' come questo valore viene letto —
         * rifiuta una stringa e solleva. Il difetto non si vede finche'
         * nessuno valorizza la variabile, perche' il valore predefinito qui e'
         * gia' un intero: si e' presentato la prima volta in integrazione
         * continua, che parte da `.env.example`, dove la riga c'e'.
         */
        (int) env('TURNSTILE_TIMEOUT', 5),
    ],

];
