<?php

declare(strict_types=1);

use App\Support\OncePerHour;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// L'orizzonte delle ricorrenze si estende una volta al mese: ogni esecuzione
// riporta a dodici mesi le date materializzate (§7.8).
Schedule::command('occurrences:generate')
    ->monthlyOn(1, '03:15')
    ->withoutOverlapping();

/*
 * L'import dei calendari (§14.2), **ogni ora**. Il comando non esegue: accoda
 * un lavoro per sorgente, così che un calendario irraggiungibile consumi i
 * propri tentativi senza ritardare gli altri.
 *
 * `withoutOverlapping()` vale per l'accodamento; la sovrapposizione che conta
 * davvero — due esecuzioni della stessa sorgente — la impedisce
 * `ShouldBeUnique` sul lavoro.
 */
Schedule::command('import:run')
    ->everyFiveMinutes()
    ->when(OncePerHour::for('import:run'))
    ->withoutOverlapping();

/*
 * Il motore delle notifiche (§15.5). Il worker gira **ogni cinque minuti**:
 * è la cadenza che lo scenario J di §18 rende verificabile — quaranta avvisi
 * di annullamento devono partire entro cinque minuti dal momento in cui la
 * serata viene annullata.
 *
 * `withoutOverlapping()` non sostituisce il blocco di riga: le righe si
 * prendono comunque con `FOR UPDATE SKIP LOCKED`, perché un lock di scheduler
 * scade e un'esecuzione lanciata a mano non lo rispetta affatto.
 */
Schedule::command('notifications:send')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// I riepiloghi si programmano in anticipo, non si scoprono al momento
// dell'invio: la stessa esecuzione purga l'archivio oltre i dodici mesi (§15.9).
Schedule::command('notifications:plan')
    ->everyFiveMinutes()
    ->when(OncePerHour::for('notifications:plan'))
    ->withoutOverlapping();

/*
 * L'archiviazione degli scaduti (§14.5), di notte presto: un evento le cui
 * date sono tutte passate da più di `eventi.archive_after_days` esce dalle
 * liste senza che nessuno debba accorgersene.
 *
 * Alle 04:10 e non alle 03:15 per non stare addosso alla generazione mensile
 * delle occorrenze: quella estende l'orizzonte, questa guarda il passato, e
 * farle girare insieme una volta al mese significherebbe archiviare mentre si
 * scrivono date nuove.
 */
Schedule::command('events:archive')
    ->dailyAt('04:10')
    ->withoutOverlapping();

/*
 * ─────────────────────────────────────────────────────────────────────────
 * Esercizio (§16)
 * ─────────────────────────────────────────────────────────────────────────
 */

/*
 * Il backup giornaliero di §16: database più storage, alle 03:40, prima che
 * l'estensione mensile delle ricorrenze cominci a scrivere.
 *
 * `withoutOverlapping(120)` e non il valore predefinito: il blocco dello
 * scheduler scade da solo, e con un'ora di margine un backup lento
 * troverebbe la propria esecuzione successiva già partita.
 *
 * `runInBackground()` è deliberatamente **assente**: in background lo
 * scheduler non conosce il codice di uscita del comando, e
 * `spatie/laravel-schedule-monitor` registrerebbe come riuscita ogni
 * esecuzione, fallimenti compresi. Un backup che fallisce in silenzio è
 * esattamente ciò che §16 vuole impedire.
 */
Schedule::command('backup:run')
    ->dailyAt('03:40')
    ->withoutOverlapping(120)
    ->graceTimeInMinutes(120);

/*
 * La pulizia applica la conservazione di 30 giorni (`config/backup.php`).
 * Gira **dopo** il backup del giorno: al contrario, la copia più recente
 * sarebbe quella di ieri e la politica scivolerebbe di un giorno.
 */
Schedule::command('backup:clean')
    ->dailyAt('04:40')
    ->withoutOverlapping()
    ->graceTimeInMinutes(60);

/*
 * `backup:monitor` è ciò che si accorge del **silenzio**: verifica che il
 * backup più recente non sia più vecchio di un giorno e che l'insieme stia
 * nello spazio dichiarato. Un comando che non parte affatto non fallisce, e
 * senza questo controllo non avviserebbe nessuno.
 */
Schedule::command('backup:monitor')
    ->dailyAt('09:00')
    ->graceTimeInMinutes(60);

/*
 * I controlli di stato di §16 girano ogni cinque minuti e **salvano** il
 * risultato: l'endpoint `/stato` rilegge l'ultimo esito invece di rieseguire
 * tutto a ogni interrogazione del monitor di uptime.
 *
 * È anche il comando che invia gli allarmi via email, con il freno di un'ora
 * dichiarato in `config/health.php`.
 */
Schedule::command('health:check')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
 * Il battito della coda: un lavoro che scrive l'ora in cui il worker lo ha
 * raccolto. È ciò che permette a `QueueCheck` di distinguere una coda vuota da
 * un worker morto — con il solo conteggio delle righe in attesa, una giornata
 * tranquilla e un worker spento si somigliano (§16, «alert su queue bloccata»).
 */
Schedule::command('health:queue-check-heartbeat')
    ->everyMinute()
    ->withoutOverlapping()
    ->doNotMonitor();

/*
 * Il battito dello scheduler stesso, per il controllo «ultima esecuzione dello
 * scheduler». Se il cron di sistema si ferma, questo battito non viene più
 * scritto e invecchia: è il solo modo che ha un sistema di accorgersi che il
 * suo orologio si è fermato, perché ogni altro controllo dipende dallo stesso
 * cron e tacerebbe insieme a lui.
 *
 * I due battiti sono gli unici comandi **fuori** dalla sorveglianza di
 * `schedule-monitor`: girano ogni minuto e scriverebbero da soli due terzi
 * dello storico, per dire una cosa che `ScheduleCheck` e `QueueCheck` già
 * dicono meglio. Sorvegliare il sorvegliante costa e non aggiunge nulla.
 */
Schedule::command('health:schedule-check-heartbeat')
    ->everyMinute()
    ->doNotMonitor();

/*
 * `spatie/laravel-schedule-monitor` allinea il registro allo scheduler: senza,
 * un comando aggiunto qui sopra resta fuori dalla sorveglianza fino al
 * rilascio successivo, ed è invisibile proprio nei giorni in cui è più
 * probabile che non funzioni.
 *
 * `docs/RUNBOOK.md` lo fa girare anche a ogni rilascio; qui è la rete di
 * sicurezza per il caso in cui quel passaggio venga saltato.
 */
Schedule::command('schedule-monitor:sync')
    ->dailyAt('03:05');

/*
 * Lo storico del monitor (`monitored_scheduled_task_log_items`) cresce di una
 * riga per ogni avvio e per ogni fine di ogni comando: con un comando ogni
 * minuto sono tre milioni di righe l'anno. La conservazione è dichiarata in
 * `config/schedule-monitor.php`.
 */
Schedule::command('model:prune', [
    '--model' => MonitoredScheduledTaskLogItem::class,
])->daily();

/*
 * **Il rilascio si controlla da se'.**
 *
 * L'integrazione continua chiama anche `POST /rilascio`, che quando arriva da'
 * l'aggiornamento immediato — ma non e' garantito che arrivi: questo host non
 * accetta connessioni dal runner ne' sulla 22 ne' sulla 443, e il firewall e'
 * dell'hosting, non nostro. Un rilascio che dipende da una porta in entrata su
 * una macchina condivisa e' un rilascio che un giorno smette senza preavviso.
 *
 * Qui invece e' il server a chiedere, e non serve che nessuno lo raggiunga.
 * `--if-behind` fa il lavoro solo quando c'e' davvero qualcosa di nuovo E gli
 * asset di quel commit sono gia' stati pubblicati: negli altri casi costa un
 * `git fetch` e finisce li.
 *
 * Cinque minuti sono il ritardo massimo fra un push e il sito aggiornato.
 */
Schedule::command('deploy:pull --if-behind')
    ->everyFiveMinutes()
    ->withoutOverlapping(30)
    ->runInBackground();
