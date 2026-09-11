<?php

declare(strict_types=1);

/*
 * Le intestazioni di sicurezza delle risposte (§16).
 *
 * **Perché stanno qui e non nel pannello dell'hosting.** In produzione
 * `nosniff`, `X-Frame-Options` e `X-XSS-Protection` arrivavano da LiteSpeed, e
 * nel repository non ce n'era traccia: funzionavano, ma nessun test le vedeva
 * e il giorno della migrazione — che è previsto, il dominio è provvisorio —
 * sarebbero sparite in silenzio. Una difesa che dipende da un pannello che
 * nessuno ha documentato è una difesa a termine.
 *
 * Quelle del server restano dove sono: il middleware non sovrascrive niente
 * che sia già stato deciso più a valle.
 */
return [

    /*
     * HSTS: «da qui in poi parla con me solo in HTTPS».
     *
     * Si emette **soltanto su richieste già cifrate** — mandarlo in chiaro non
     * ha effetto e la specifica dice di ignorarlo — e vale per il singolo
     * nome host.
     *
     * `preload` resta **spento** di proposito: iscriversi all'elenco dei
     * browser è facile e uscirne richiede mesi, e non è una decisione da
     * prendere di sfuggita su un dominio che non è quello definitivo.
     *
     * ## `includeSubDomains` e le città
     *
     * Se un giorno le città vivono su `padova.incitta.it` e
     * `bologna.incitta.it`, questa riga smette di riguardare un nome solo:
     * servita dall'apice, impegna **tutti** i sottodomini presenti e futuri a
     * rispondere in HTTPS, per un anno, anche quelli che non esistono ancora.
     *
     * Non è un motivo per spegnerla — è la difesa giusta — ma è un motivo per
     * saperlo prima: una città nuova pubblicata senza certificato valido non
     * sarà «lenta» o «con l'avviso», sarà **irraggiungibile** da chiunque
     * abbia già visitato una delle altre, e senza modo di proseguire. Il
     * certificato va prima del DNS, non dopo.
     */
    'hsts' => [
        'enabled' => env('SECURITY_HSTS', true),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31_536_000),
        'include_subdomains' => env('SECURITY_HSTS_SUBDOMAINS', true),
        'preload' => env('SECURITY_HSTS_PRELOAD', false),
    ],

    /*
     * La politica dei contenuti.
     *
     * **Non restringe gli script, ed è una scelta.** Il documento contiene
     * codice in linea — quello che applica il tema prima che il CSS dipinga —
     * e gli script del consenso che il pannello lascia scrivere a mano. Una
     * `script-src` seria vuole un nonce su ognuno di quelli: è un lavoro a sé,
     * e una CSP scritta di corsa con `'unsafe-inline'` dentro non protegge da
     * niente pur sembrando di sì.
     *
     * Quello che c'è protegge da cose vere e non rompe niente: nessun
     * `<object>`, nessun `<base>` riscritto, nessun modulo che spedisce
     * altrove, nessuna cornice da un altro sito.
     *
     * **`'self'` vuol dire stessa origine, non stesso dominio.** Con le città
     * su sottodomini, `bologna.incitta.it` non potrà incorniciare
     * `padova.incitta.it` né viceversa, e nemmeno un eventuale portale
     * sull'apice potrà incorniciare una città. Se quel portale arriverà, qui
     * servirà `frame-ancestors 'self' https://*.incitta.it` — e va cambiato
     * qui, non aggirato nei singoli controller.
     */
    'csp' => env('SECURITY_CSP', "base-uri 'self'; object-src 'none'; form-action 'self'; frame-ancestors 'self'"),

    /*
     * La parte della politica che riguarda **gli script**, e che si aggiunge
     * alla riga qui sopra.
     *
     * Vive separata perché ha un nonce dentro — cambia a ogni risposta — e
     * perché non vale ovunque: i pannelli sono Filament, che disegna markup
     * suo, e non stampano contenuti di sconosciuti. Vedi `App\Support\Csp`.
     *
     * `excluded_paths` usa la sintassi di `Request::is()`: `admin*` copre sia
     * `/admin` sia tutto ciò che ci sta sotto.
     */
    // MapLibre creates blob workers; notifications use the same-origin service worker.
    'worker_src' => "worker-src 'self' blob:",

    'script_src' => [
        'enabled' => env('SECURITY_SCRIPT_SRC', true),
        'excluded_paths' => [
            'admin*',
            'gestione*',
            'organizza*',
            'installazione*',
            'docs/api*',
            'livewire/*',
            'api/*',
        ],
    ],

    /*
     * Quanto del nostro indirizzo viaggia verso i siti che linkiamo: origine e
     * percorso dentro il sito, la sola origine verso l'esterno, niente del
     * tutto verso HTTP. È il valore predefinito dei browser moderni, scritto
     * perché non dipenda da quale browser è.
     */
    'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

    /*
     * Le capacità del browser che questo sito può chiedere. La posizione sì —
     * «vicino a me» (§11.7) la usa, e solo su gesto esplicito — il resto no.
     */
    'permissions_policy' => env('SECURITY_PERMISSIONS_POLICY', 'geolocation=(self), camera=(), microphone=(), payment=(), usb=()'),

];
