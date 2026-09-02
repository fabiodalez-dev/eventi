<?php

declare(strict_types=1);

/*
 * Account, salvataggi e follow (§15).
 *
 * Qui stanno le soglie di prodotto — quando offrire il promemoria, quanto vive
 * un collegamento di accesso — e nulla che riguardi la forma delle risposte.
 */
return [

    /*
     * Dopo quanti salvataggi da anonimo compare l'invito ad accedere (§15.1,
     * rivisto — vedi D49).
     *
     * Era 3, con un riquadro discreto in fondo alla pagina: al primo click
     * nessuna interruzione, al terzo la persona ha gia' dimostrato di
     * tornare. Ora e' 1 e l'invito e' un dialogo, perche' il primo
     * salvataggio era muto — chi ne fa uno solo non scopriva mai che vive
     * soltanto in quel browser, e cambiando telefono lo perdeva senza essere
     * mai stato avvisato.
     *
     * **Il salvataggio avviene comunque, prima che il dialogo si apra.** Il
     * click non si perde e non si sospende in attesa di una registrazione:
     * quello resta il punto fermo di §15.1.
     */
    'guest_save_prompt_after' => 1,

    /*
     * Quanti identificativi accetta al massimo `POST /v1/me/saved/merge`. È il
     * tetto del `localStorage` di chi ha salvato per mesi senza registrarsi;
     * oltre, la richiesta non è più una migrazione ma un carico.
     */
    'merge_max' => 200,

    /*
     * Durata in minuti del collegamento di accesso senza password (§15.2) e
     * di quello di verifica dell'email. Quindici minuti sono abbastanza per
     * passare dalla casella al browser e troppo pochi perché un messaggio
     * inoltrato per sbaglio resti una chiave.
     */
    'magic_link_minutes' => 15,
    'verification_link_minutes' => 60,

    /*
     * Le lingue che un profilo può scegliere (§15.2). Sono le cartelle di
     * `lang/`: `en` e `de` esistono e sono vuote, ma la colonna `users.locale`
     * le accetta già — cambiare lingua non deve richiedere una migration.
     */
    'locales' => ['it', 'en', 'de'],

    /*
     * Dominio degli indirizzi anonimizzati alla cancellazione dell'account
     * (§15.9). `.invalid` è riservato dalla RFC 2606: nessun messaggio potrà
     * mai partire verso un indirizzo così, che è esattamente ciò che serve.
     */
    'anonymized_email_domain' => 'anonimo.invalid',

    /*
     * Quante occorrenze mostra una pagina del feed personalizzato (§15.7) e
     * quante proposte mostra il suo avvio guidato quando non si segue ancora
     * nulla: mai una pagina vuota.
     */
    'feed_per_page' => 24,
    'onboarding_venues' => 8,
    'onboarding_categories' => 8,

];
