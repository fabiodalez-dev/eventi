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
     * Le lingue che un profilo può scegliere (§15.2).
     *
     * **Solo quelle tradotte davvero.** `lang/en` e `lang/de` esistono ma
     * contengono un `.gitkeep` e nient'altro, e `APP_FALLBACK_LOCALE` punta a
     * sua volta a `en`: offrirle significava, nel migliore dei casi, un
     * comando che non fa niente — `user.locale` viene salvato, esposto
     * nell'API e mandato ai dispositivi, ma nessuno chiama `setLocale()`, e il
     * sito resta in italiano qualunque cosa si scelga. Nel peggiore, il giorno
     * in cui qualcuno collega il campo alla lingua dell'applicazione, chi
     * aveva scelto EN o DE si ritrova le pagine piene di chiavi grezze
     * (`ui.nav.today`, `events.title`), perché anche il ripiego è vuoto.
     *
     * La colonna `users.locale` continua ad accettare qualunque valore:
     * aggiungere una lingua resta una riga qui, non una migration. Si aggiunge
     * **dopo** aver riempito la cartella, non prima.
     */
    'locales' => ['it'],

    /*
     * Quanti dispositivi push puo' tenere iscritti un account.
     *
     * Ogni endpoint diverso e' una riga in `devices`, e l'endpoint lo scrive
     * chi chiama: senza un tetto, un account qualsiasi puo' depositarne a
     * piacere. Dieci coprono chiunque — telefono, tablet, due computer, un
     * paio di browser per ognuno — e oltre si ricicla il piu' vecchio invece
     * di rifiutare, perche' chi ha davvero cambiato dispositivo deve poter
     * iscrivere quello nuovo.
     */
    'max_devices_per_user' => 10,

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
