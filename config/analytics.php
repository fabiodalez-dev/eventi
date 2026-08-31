<?php

declare(strict_types=1);

/*
 * Analitica senza cookie (§16: «Analytics privacy-first»; §12 dello stack
 * tecnologico: Plausible o Umami).
 *
 * **Le tre variabili vuote significano nessuna analitica.** Non un contatore
 * spento che si scalda a vuoto: nessuno `<script>`, nessuna richiesta a terzi,
 * nessuna riga in più nell'HTML. È la condizione predefinita del progetto, ed
 * è quella con cui gira in prova e nei test.
 *
 * Perché tre variabili e non una: `ANALYTICS_SRC` è l'indirizzo del proprio
 * server di statistiche (self-hosted, che è il motivo per cui questa scelta è
 * compatibile con §16: nessun trasferimento verso terzi fuori dall'Unione), e
 * `ANALYTICS_DOMAIN` è ciò che identifica il sito presso quel server. I due
 * strumenti lo chiamano in modo diverso — Plausible `data-domain`, Umami
 * `data-website-id` — e quale attributo emettere lo sa `AnalyticsProvider`,
 * non chi scrive il file `.env`.
 */
return [

    /*
     * `plausible` o `umami`. Vuoto — o un nome che non corrisponde a nessuno
     * dei due — spegne tutto: un valore sbagliato non deve produrre uno script
     * a metà, che caricherebbe un file inesistente da un dominio esterno.
     */
    'provider' => env('ANALYTICS_PROVIDER', ''),

    /*
     * Il sito presso il server di statistiche: il dominio per Plausible,
     * l'identificativo del sito per Umami.
     */
    'domain' => env('ANALYTICS_DOMAIN', ''),

    /*
     * L'indirizzo completo dello script. Deve essere `https`: un contatore
     * servito in chiaro dentro una pagina cifrata verrebbe bloccato dal
     * browser, e l'unico segnale sarebbe una riga in console che nessuno
     * guarda.
     */
    'src' => env('ANALYTICS_SRC', ''),

];
