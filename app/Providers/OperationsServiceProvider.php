<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Features;
use App\Support\Health\ImportSourcesCheck;
use App\Support\Health\ScheduledTasksCheck;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

/**
 * L'operatività di §16: che cosa viene sorvegliato e quali funzioni possono
 * essere accese o spente.
 *
 * Sta fuori da `AppServiceProvider` perché non è dominio: qui non si dichiara
 * nulla di ciò che il prodotto fa, si dichiara come ci si accorge che ha
 * smesso di farlo.
 */
final class OperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerHealthChecks();

        Features::define();

        /*
         * `@newsletter` invece del `@feature` di Pennant: quello usa come
         * ambito l'utente collegato, mentre questo interruttore vale per tutto
         * il sistema (`App\Support\Features::globalScope()`). Con la
         * direttiva del pacchetto ogni visitatore si porterebbe una riga in
         * `features` per una risposta che è la stessa per tutti.
         */
        Blade::if('newsletter', static fn (): bool => Features::newsletterActive());
    }

    /**
     * I controlli che contano su questo impianto. Non tutti quelli che il
     * pacchetto offre: `RedisCheck` e `HorizonCheck` non hanno senso dove non
     * c'è Redis (D5), e un controllo che non può che fallire insegna a
     * ignorare la pagina.
     */
    private function registerHealthChecks(): void
    {
        Health::checks([
            /*
             * Il database: apre la connessione e fa una interrogazione. È il
             * primo dei quattro allarmi che §16 elenca, ed è anche l'unico che
             * spiega da solo un sito che risponde 500 su tutto.
             */
            DatabaseCheck::new()->label(__('health.labels.database')),

            /*
             * La cache: scrive un valore e lo rilegge. Su questo impianto è il
             * disco (D5), quindi un fallimento qui di solito significa permessi
             * o spazio in `storage/framework/cache`, non un demone caduto.
             */
            CacheCheck::new()->label(__('health.labels.cache')),

            /*
             * «Storage quasi pieno» (§16). Quando il disco finisce si fermano
             * insieme i backup, i log, le locandine caricate e le sessioni.
             *
             * **Le soglie sono alte di proposito.** Su questa shared hosting il
             * disco è condiviso fra tutti gli account: 1,3 TB di cui 52 GB
             * liberi, mentre questa installazione ne occupa dieci in tutto.
             * Quel 96% racconta i vicini, non noi, e non c'è niente da fare
             * per abbassarlo — con l'allarme all'85% `/stato` risponde 503
             * ogni giorno per una cosa su cui nessuno può agire, e un monitor
             * che grida sempre viene messo a tacere. Che è il modo in cui si
             * perde anche l'allarme vero.
             *
             * A queste soglie il segnale torna a significare qualcosa: sotto i
             * pochi punti percentuali rimasti, la scrittura fallisce davvero e
             * la risposta è aprire un ticket all'hosting, non cancellare
             * qualcosa.
             */
            UsedDiskSpaceCheck::new()
                ->label(__('health.labels.disk'))
                ->warnWhenUsedSpaceIsAbovePercentage(95)
                ->failWhenUsedSpaceIsAbovePercentage(98),

            /*
             * «Queue bloccata» (§16). Il controllo non conta le righe in
             * attesa: mette in coda un lavoro che scrive un battito, e verifica
             * che qualcuno lo abbia raccolto. È l'unico modo di distinguere una
             * coda vuota da un worker morto — con il solo conteggio, un worker
             * spento e una giornata tranquilla si somigliano.
             *
             * Il cron rilancia `queue:work --stop-when-empty` ogni minuto
             * (`docs/RUNBOOK.md`): cinque minuti di margine coprono un lavoro
             * lungo in corso senza lasciar passare un worker che non riparte.
             */
            QueueCheck::new()
                ->label(__('health.labels.queue'))
                ->failWhenHealthJobTakesLongerThanMinutes(5),

            /*
             * «Ultima esecuzione dello scheduler». Il battito lo scrive un
             * comando schedulato ogni minuto: se il cron di sistema si ferma,
             * il battito invecchia e questo controllo se ne accorge.
             *
             * Il margine è di tre minuti e non di uno: su una shared hosting il
             * minuto del cron slitta, e un allarme che scatta per uno slittamento
             * normale è un allarme che si impara a ignorare.
             */
            ScheduleCheck::new()
                ->label(__('health.labels.schedule'))
                ->heartbeatMaxAgeInMinutes(3),

            /*
             * «Import fallito» (§16), nella forma che il piano descrive: le
             * sorgenti attive non sono tutte in errore.
             */
            ImportSourcesCheck::new()->label(__('health.labels.import_sources')),

            /*
             * L'allarme che rende utile `spatie/laravel-schedule-monitor`: un
             * comando schedulato che smette di girare o che fallisce. È il
             * motivo per cui lo stack ha scelto il pacchetto — «il worker delle
             * notifiche è critico: se muore in silenzio nessuno riceve più
             * promemoria».
             */
            ScheduledTasksCheck::new()->label(__('health.labels.scheduled_tasks')),
        ]);
    }
}
