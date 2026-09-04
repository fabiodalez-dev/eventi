<?php

declare(strict_types=1);

/*
 * Il banner del consenso (§16).
 *
 * I due pulsanti dicono la stessa cosa in due direzioni e sono lunghi quasi
 * uguale: «Accetta» e «Rifiuta». Un «Accetta tutto» accanto a un «Continua
 * senza accettare» non è una scelta paritaria, è una scelta con una strada in
 * discesa e una in salita.
 */
return [

    'title' => 'Due parole su cosa salviamo',

    /* Onesto e verificabile: è esattamente ciò che il sito fa. */
    'body' => 'Questo sito non usa cookie pubblicitari e non profila nessuno. Conserva sul tuo dispositivo solo quello che gli serve per funzionare — la sessione, la protezione dei moduli, le date che metti in agenda — e ci piacerebbe contare in forma anonima le pagine viste, per capire cosa serve davvero.',

    /* Quando ANALYTICS_* è vuoto lo strumento non esiste: dirlo è più onesto
       che chiedere un consenso per qualcosa che non parte comunque. */
    'body_without_analytics' => 'Questo sito non usa cookie pubblicitari, non profila nessuno e al momento non ha alcuno strumento di statistica attivo. Conserva sul tuo dispositivo solo quello che gli serve per funzionare: la sessione, la protezione dei moduli e le date che metti in agenda. La tua scelta resta registrata e varrà anche il giorno in cui un contatore anonimo verrà attivato.',

    'accept' => 'Accetta',
    'reject' => 'Rifiuta',

    'preferences' => 'Scegli nel dettaglio',
    'preferences_hint' => 'Puoi accettare una finalità e rifiutare l’altra.',
    'save_preferences' => 'Salva le preferenze',

    'always_active' => 'Sempre attivi',

    'read_policy' => 'Informativa privacy',
    'read_cookies' => 'Cookie policy',

    'saved' => 'Grazie: la tua scelta è stata registrata.',
    'revoked' => 'La tua scelta è stata cancellata: te la chiederemo di nuovo.',

    /* Il pannello di gestione dentro la Cookie Policy: chi vuole cambiare idea
       non deve cercare il banner, che nel frattempo è sparito. */
    'manage' => [
        'title' => 'Le tue preferenze',
        'current_none' => 'Non hai ancora espresso una scelta.',
        'current_granted' => 'Hai accettato le statistiche anonime.',
        'current_partial' => 'Adesso hai acconsentito a: :elenco.',
        'choose' => 'Scegli a cosa acconsentire',
        'save' => 'Salva la scelta',
        'current_denied' => 'Hai rifiutato le statistiche anonime.',
        'revoke' => 'Cancella la mia scelta',
    ],

    'analytics' => [
        /* Serve alla Cookie Policy per dire il vero anche quando la
           configurazione cambia: il testo si legge, lo stato si guarda. */
        'active' => 'In questo momento è attivo :provider, ospitato su :host.',
        'inactive' => 'In questo momento non è attivo alcuno strumento di statistica.',
    ],

];
