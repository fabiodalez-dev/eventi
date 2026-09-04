<?php

declare(strict_types=1);

/*
 * L'API pubblica v1 (§13).
 *
 * Il contratto è stabile: dentro la v1 non si tolgono campi e non se ne
 * cambia il significato. Quello che può cambiare senza rompere nessuno è
 * ciò che sta qui — soglie, limiti, interruttori — perché è configurazione
 * e non forma della risposta.
 */
return [

    /*
     * Versione dichiarata da `GET /v1/config`. Non è la versione
     * dell'applicazione: è quella del contratto.
     */
    'version' => 'v1',

    /*
     * Versione minima dell'app mobile ammessa (§13.1). Un client più vecchio
     * riceve comunque i dati: sta all'app decidere cosa farne, ed è per
     * questo che il numero viaggia nella configurazione invece di diventare
     * un rifiuto lato server.
     */
    'min_app_version' => [
        'ios' => env('API_MIN_APP_VERSION_IOS', '1.0.0'),
        'android' => env('API_MIN_APP_VERSION_ANDROID', '1.0.0'),
    ],

    /*
     * Quanti elementi restituisce una pagina. Il massimo è quello di §13.2:
     * oltre 50 la risposta smette di essere una pagina e diventa un dump.
     */
    'limits' => [
        'default' => 24,
        'max' => 50,

        /*
         * La mappa è l'eccezione dichiarata da §13.3: centinaia di marcatori
         * con un carico minimo pesano meno di venti card complete.
         */
        'map_default' => 200,
        'map_max' => 500,

        /*
         * Quanti risultati per ciascun gruppo di `GET /v1/search`.
         */
        'search' => 10,

        /*
         * Quante date future accompagnano la scheda di un evento.
         */
        'event_dates' => 24,
    ],

    /*
     * Intestazioni di cache delle liste pubbliche (§13 e §12.3). Un minuto di
     * validità e cinque di "servi il vecchio mentre rinfreschi": una lista di
     * eventi che cambia una volta al giorno non ha bisogno di più.
     */
    'cache' => [
        'max_age' => 60,
        'stale_while_revalidate' => 300,
    ],

    /*
     * Limiti di frequenza di §13.4, in richieste al minuto.
     */
    'rate_limit' => [
        'anonymous' => 60,
        'authenticated' => 120,

        /*
         * Accesso, registrazione e reimpostazione password hanno un limite
         * proprio e molto più stretto (§16): sono i tre indirizzi che qualcuno
         * proverà a forzare.
         */
        'auth' => 6,
    ],

    /*
     * Interruttori che l'app legge all'avvio (§13.1). Dicono che cosa esiste
     * davvero in questo momento, non che cosa esisterà.
     *
     * `push` resta spento anche dopo D54, e non è una dimenticanza: questi
     * interruttori parlano a un'app **nativa**, e per lei push significa FCM,
     * che appartiene a F11. Il Web Push riaperto da D54 vive nel browser, dove
     * nessuna app lo legge. Accendere questo interruttore adesso farebbe
     * comparire in un'app un pulsante che non funziona, che è esattamente ciò
     * che questo endpoint esiste per evitare.
     */
    'features' => [
        'map' => true,
        'calendar' => true,
        'search' => true,
        'submissions' => true,
        'reports' => true,
        'accounts' => true,
        'saved_events' => true,
        'follows' => true,
        'push' => false,
    ],

    /*
     * Dove porta il collegamento «reimposta la password» del messaggio.
     * `{token}` e `{email}` vengono sostituiti. È un indirizzo dell'app
     * (schema personalizzato o universal link): la pagina web equivalente non
     * esiste ancora, e quando esisterà basterà cambiare questa riga.
     */
    'password_reset_url' => env('API_PASSWORD_RESET_URL'),

    /*
     * Testi legali (§13.1, §16). L'app mostra i link e la data dell'ultima
     * revisione: se la data cambia, l'app sa di dover richiedere il consenso.
     */
    'legal' => [
        'terms_url' => env('API_LEGAL_TERMS_URL'),
        'privacy_url' => env('API_LEGAL_PRIVACY_URL'),
        'updated_at' => env('API_LEGAL_UPDATED_AT'),
    ],

];
