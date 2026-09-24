<?php

declare(strict_types=1);

/*
 * Il biglietto nel portafoglio digitale.
 *
 * La funzione è spenta finché queste quattro voci non ci sono tutte, e spenta
 * significa che `GET /v1/wallet` risponde `false` e l'app non disegna il
 * pulsante: un interruttore che il server ignora è peggio di nessun
 * interruttore, come già vale per le chiavi VAPID del push del browser.
 *
 * Le credenziali arrivano da una registrazione presso Google — console
 * dell'emittente, conto di servizio, chiave privata — ed è un passaggio
 * amministrativo, non solo codice:
 *
 * - `GOOGLE_WALLET_ISSUER_ID`: il numero dell'emittente della console Wallet.
 * - `GOOGLE_WALLET_CLASS_ID`: il suffisso della classe biglietto già creata in
 *   console, senza il numero dell'emittente davanti.
 * - `GOOGLE_WALLET_SERVICE_ACCOUNT_EMAIL`: l'indirizzo del conto di servizio
 *   autorizzato sull'emittente.
 * - `GOOGLE_WALLET_PRIVATE_KEY`: la chiave privata PEM di quel conto. Nel file
 *   `.env` va fra virgolette con gli a capo scritti `\n`.
 *
 * `GOOGLE_WALLET_ORIGINS` è facoltativo: limita i domini da cui il link
 * firmato può essere aperto. Vuoto significa nessun vincolo di origine, che è
 * ciò che serve quando ad aprirlo è un'app e non una pagina.
 */
return [

    'google' => [
        'issuer_id' => env('GOOGLE_WALLET_ISSUER_ID'),
        'class_id' => env('GOOGLE_WALLET_CLASS_ID'),
        'service_account_email' => env('GOOGLE_WALLET_SERVICE_ACCOUNT_EMAIL'),
        'private_key' => env('GOOGLE_WALLET_PRIVATE_KEY'),
        'origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GOOGLE_WALLET_ORIGINS', '')),
        ))),
    ],

];
