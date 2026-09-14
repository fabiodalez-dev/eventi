<?php

declare(strict_types=1);

/*
 * La tabella di §12.3, tradotta in numeri. Le voci non sono intercambiabili:
 * ognuna risponde a quanto in fretta quel contenuto smette di essere vero.
 */
return [
    'max_entries' => 200,

    /*
     * Lo spegnimento serve allo sviluppo — una modifica a una vista deve
     * vedersi ricaricando — e ai test che non riguardano la cache: una pagina
     * servita dalla copia salvata non eseguirebbe il codice che stanno
     * verificando. Il test della cache la riaccende.
     */
    'enabled' => filter_var(env('PAGE_CACHE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // Null preserves the application's store. frontend-redis is opt-in only.
    'store' => env('PAGE_CACHE_STORE') ?: null,

    /*
     * Lo scheletro delle pagine pubbliche resta disponibile per almeno trenta
     * minuti. La freschezza non dipende da una scadenza breve: la chiave porta
     * ContentVersion e cambia appena viene pubblicato o modificato un contenuto.
     * Le finestre che cambiano di minuto in minuto hanno una cache separata.
     *
     * Il pavimento impedisce a una vecchia variabile d'ambiente di riportare
     * accidentalmente la produzione al precedente TTL di uno/cinque minuti.
     */
    'ttl_minutes' => max(30, (int) env('PAGE_CACHE_TTL_MINUTES', 30)),

    /*
     * "In corso" e "Inizia tra poco": sessanta secondi (§12.3). Sono le due
     * finestre che cambiano di minuto in minuto, e stanno in un frammento
     * caricato a parte proprio per poter avere un tempo tutto loro.
     */
    'live_ttl_seconds' => 60,

    /*
     * **L'arrotondamento che rende la cache utile** (§12.3).
     *
     * "In corso adesso" dipende dall'istante. Se l'istante entrasse nella
     * chiave così com'è, ogni secondo ne genererebbe una nuova: si scriverebbe
     * sempre e non si leggerebbe mai, cioè si pagherebbe il costo di una cache
     * senza averne il beneficio. Arrotondato al quarto d'ora, tutte le
     * richieste dello stesso quarto d'ora chiedono la stessa chiave.
     */
    'live_rounding_minutes' => 15,

    /*
     * Tassonomie — categorie, tag, comuni, locali del pannello dei filtri:
     * ventiquattro ore (§12.3). Cambiano quando la redazione le cambia, cioè
     * qualche volta l'anno.
     */
    'taxonomy_ttl_hours' => 24,

];
