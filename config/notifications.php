<?php

declare(strict_types=1);

/*
 * Il motore di invio delle notifiche (§15.4, §15.5, §15.6).
 *
 * Qui stanno le soglie che governano il **volume** e la resistenza ai guasti.
 * Nulla che riguardi il contenuto dei messaggi: quello sta in `lang/it`.
 *
 * I canali attivi sono email e archivio in-app (D8): Web Push non è
 * installabile su Laravel 13. Il motore resta quello descritto da §15.5 —
 * chiave di deduplica, riprogrammazione, ore di silenzio, tetto giornaliero:
 * cambia soltanto il canale scelto in fondo alla catena.
 */
return [

    /*
     * Quante righe preleva il worker a ogni giro. Il prelievo avviene con
     * `SELECT ... FOR UPDATE SKIP LOCKED` dentro una transazione: senza Redis
     * non esiste un lock distribuito (D5), e il blocco di riga del database è
     * ciò che impedisce a due esecuzioni sovrapposte di prendere le stesse
     * righe. Il vincolo `dedupe_key UNIQUE` resta l'ultima garanzia.
     */
    'batch' => 100,

    /*
     * Tentativi e attesa fra l'uno e l'altro (§15.5: «retry esponenziale, max
     * 3»). Alla scadenza dell'ultimo tentativo la riga passa a `failed` e
     * resta visibile nel pannello con l'errore che l'ha fermata.
     */
    'max_attempts' => 3,
    'retry_backoff_minutes' => [5, 15, 45],

    /*
     * §15.4: «massimo 2 push al giorno per utente, esclusi i promemoria di
     * eventi salvati esplicitamente». Il conteggio si fa su `notification_log`,
     * nella giornata locale di chi riceve, e riguarda i soli tipi che
     * `NotificationType::countsTowardDailyCap()` dichiara intrusivi.
     */
    'daily_cap' => 2,

    /*
     * Le ore di silenzio (§15.4). Un invio che cade nella finestra si sposta
     * all'orario di uscita; se nel frattempo è diventato inutile diventa
     * `skipped`.
     *
     * `default` è la finestra di **chi non ha scelto** (D36). Prima non
     * esisteva, e chi non apriva le preferenze poteva ricevere un promemoria
     * alle tre di notte: un valore predefinito assente non è neutralità, è la
     * scelta peggiore presa per omissione. 23:00-08:00 è la notte come la
     * intende chiunque, e non tocca nulla di ciò che il prodotto manda
     * davvero — i promemoria escono a ore di veglia e i riepiloghi hanno un
     * orario proprio.
     *
     * Chi le vuole diverse le scrive; chi non ne vuole affatto lo dichiara, e
     * allora `users.quiet_hours` contiene un array vuoto invece di `null`. È
     * la differenza fra «non ho ancora deciso» e «ho deciso di no», e senza
     * quella differenza il valore predefinito sarebbe impossibile da spegnere.
     *
     * Mettere `default` a `null` riporta il comportamento di prima: nessun
     * silenzio per chi non lo dichiara.
     */
    'quiet_hours' => [
        'enabled' => true,

        'default' => [
            'from' => env('QUIET_HOURS_DEFAULT_FROM', '23:00'),
            'to' => env('QUIET_HOURS_DEFAULT_TO', '08:00'),
        ],
    ],

    /*
     * I riepiloghi (§15.4). Mai una notifica per singolo evento nuovo di un
     * locale seguito: sempre un riepilogo aggregato.
     *
     * `weekday` è il giorno ISO (1 = lunedì). Il riepilogo settimanale esce di
     * martedì sera perché la settimana ha già qualcosa dentro e mancano ancora
     * quattro giorni per decidere; la newsletter del weekend esce di giovedì
     * pomeriggio, come prescrive §15.4.
     */
    'digests' => [
        'venue' => [
            'weekday' => 2,
            'time' => '18:00',
            'window_days' => 7,
            'max_items' => 8,
        ],
        'daily' => [
            'time' => '17:00',
            'max_items' => 6,
        ],
        'weekend' => [
            'weekday' => 4,
            'time' => '16:00',
            'max_items' => 8,
        ],
    ],

    /*
     * Quanto guarda avanti il pianificatore dei riepiloghi: le righe vengono
     * create in anticipo proprio perché §15.5 vuole che ogni invio previsto
     * sia **visibile e verificabile prima** che parta.
     */
    'planning_horizon_hours' => 36,

    /*
     * §15.4, ai gestori: «non pubblichi da 21 giorni». L'avviso non si ripete
     * più di una volta al mese per locale — la chiave di deduplica porta
     * l'anno e il mese.
     */
    'venue_inactivity_days' => 21,

    /*
     * §15.9: `notification_log` conserva dodici mesi. La purga gira insieme
     * alla pianificazione, perché è l'unico comando che passa una volta l'ora
     * e non ogni cinque minuti.
     */
    'log_retention_months' => 12,

    /*
     * Quanto vale il collegamento firmato che apre le preferenze senza
     * accesso (§15.9). Trenta giorni: è la vita utile di un messaggio nella
     * casella di chi lo riceve, non di una credenziale.
     */
    'preference_link_days' => 30,

];
