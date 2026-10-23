<?php

declare(strict_types=1);

return [
    /*
     * La finestra entro cui chi viene promosso dalla lista d'attesa deve
     * confermare. Dodici ore coprono una notte: chi riceve l'avviso a mezzanotte
     * lo trova la mattina e fa in tempo. Scaduta, il posto torna in circolo.
     */
    'promotion' => [
        'confirm_hours' => 12,
        /*
         * Sotto questa soglia dall'inizio non si promuove più nessuno: un
         * posto assegnato un'ora prima a chi non lo vedrà resta un posto vuoto,
         * e chi si presenta alla porta lo troverebbe occupato da nessuno.
         */
        'min_hours_before' => 2,
    ],

    /*
     * Chi prenota e non si presenta. Un biglietto resta «valido» per sempre
     * se nessuno lo guarda più; qui si decide quando diventa un'assenza e
     * quante assenze recenti bloccano una nuova prenotazione gratuita.
     * Il conteggio vale solo per le date in cui il locale ha davvero usato
     * il controllo ingressi: dove nessuno ha scansionato niente, nessuno è
     * un assente.
     */
    'no_show' => [
        'grace_hours' => 2,
        'limit' => 3,
        'window_days' => 90,
        /*
         * Quota minima di biglietti prenotati da account passati dalla porta
         * perché la serata faccia prova. Sotto, il locale non ha usato il
         * controllo ingressi per davvero e non si segna nessuno.
         */
        'min_checked_in_share' => 0.25,
    ],

    /*
     * Le prenotazioni prese al banco con un'email: quante conferme al giorno
     * per data possono partire verso indirizzi scritti dal personale.
     */
    'guest' => [
        'daily_mail_limit' => 50,
    ],

    /*
     * I messaggi del locale a chi ha prenotato una data: quanti al giorno per
     * data, perché un canale verso persone che hanno solo prenotato un posto
     * non deve diventare una newsletter.
     */
    /*
     * Le letture fatte senza rete si sincronizzano anche a serata finita:
     * per quante ore dopo la chiusura della porta il server le accetta,
     * purché l'istante della scansione cada dentro la serata.
     */
    'checkin' => [
        'late_sync_hours' => 24,
    ],

    'messages' => [
        'daily_limit' => 3,
    ],
];
