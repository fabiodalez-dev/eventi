<?php

declare(strict_types=1);

/*
 * Il motore di import di §14.2. I valori che stanno qui sono scelte di
 * esercizio — quanto si aspetta un calendario lento, quante parole tengono
 * fuori le voci interne — e cambiano senza toccare il codice.
 *
 * Ogni sorgente può sovrascriverli nella propria colonna `mapping` (json):
 * quello che si legge qui è il valore in vigore quando la sorgente tace.
 */
return [

    /*
     * Secondi concessi al calendario remoto. `connect` è il tempo per aprire
     * la connessione, `request` quello per riceverla per intero: un ICS di
     * qualche migliaio di date su una linea lenta arriva in decine di secondi,
     * ma un server che non risponde affatto deve arrendersi subito.
     */
    'timeout' => 30,

    'connect_timeout' => 10,

    /*
     * Byte massimi accettati da una risposta. Un calendario pubblico che
     * supera questa soglia non è un calendario: è un guasto, o qualcuno che
     * ci sta puntando contro un file enorme.
     */
    'max_bytes' => 8 * 1024 * 1024,

    /*
     * Quanti salti di redirect si seguono. Un calendario che ne chiede di più
     * non sta reindirizzando: sta girando in tondo, o sta cercando di portare
     * la richiesta altrove. Ogni salto viene comunque ricontrollato da
     * `ImportUrlGuard`, che è la difesa vera; questo è solo il tetto.
     */
    'max_redirects' => 3,

    /*
     * Quante esecuzioni si conservano per sorgente (`import_runs`). Servono a
     * rispondere a «da quando non funziona più» e «che cosa ha fatto ieri»:
     * oltre il centinaio non risponderebbero a nessuna domanda in più e la
     * tabella crescerebbe per sempre.
     */
    'history_size' => 50,

    /*
     * §14.2, contrappeso alla pubblicazione diretta (D32): un evento il cui
     * titolo contiene una di queste parole non entra. È ciò che tiene fuori
     * "riunione staff" e "chiuso per ferie" dal calendario Google di un
     * locale, che sono voci interne e non eventi.
     *
     * Il confronto è senza distinzione di maiuscole, senza accenti e su
     * **parole intere**: una sottostringa escluderebbe un evento vero al primo
     * elenco un po' più lungo — "arte" ucciderebbe "Cartellone", "privato"
     * ucciderebbe "Privatoteca". Per la stessa ragione "chiuso" e "chiusura"
     * sono due voci distinte: la prima non contiene la seconda.
     */
    'exclude_keywords' => [
        'chiuso',
        'ferie',
        'riunione',
        'privato',
        'manutenzione',
        'chiusura',
    ],

    /*
     * Quanti errori di mappatura vengono riportati per esteso nel resoconto
     * di un'esecuzione. Oltre questa soglia resta il conteggio: `last_error`
     * è una colonna di testo che qualcuno deve poter leggere.
     */
    'reported_errors' => 5,

    /*
     * Date esaminate dall'anteprima di §14.2 («preview» prima della
     * moderazione). Venti bastano a capire se il filtro sta lavorando bene e
     * se i fusi sono stati interpretati come ci si aspetta.
     */
    'preview_size' => 20,

    /*
     * Tentativi del lavoro di coda e attesa fra l'uno e l'altro, in secondi.
     * Una sorgente irraggiungibile riprova due volte a distanza crescente e
     * poi si arrende: le altre sorgenti non la aspettano, perché ognuna ha il
     * proprio lavoro.
     */
    'job' => [
        'tries' => 3,
        'backoff' => [60, 300],
        'timeout' => 120,
    ],

];
