<?php

declare(strict_types=1);

use Spatie\ScheduleMonitor\Jobs\PingOhDearJob;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem;

/*
 * Il registro dei comandi schedulati (§12 dello stack tecnologico: «il worker
 * delle notifiche è critico: se muore in silenzio nessuno riceve più
 * promemoria»).
 *
 * Il pacchetto **registra e non avvisa**: scrive quando ogni comando è partito
 * e quando è finito. L'allarme lo produce `App\Support\Health\ScheduledTasksCheck`,
 * che legge questo registro e lo traduce in uno stato che `health:check` sa
 * notificare via email. Sono due pacchetti e un ponte, non due sistemi.
 *
 * Quali comandi siano sorvegliati non si dichiara qui: lo sono **tutti** quelli
 * di `routes/console.php` che non chiamano `doNotMonitor()`, e
 * `schedule-monitor:sync` allinea il registro allo scheduler.
 */
return [

    /*
     * Lo storico (`monitored_scheduled_task_log_items`) cresce di una riga per
     * ogni avvio e per ogni fine di ogni comando. Trenta giorni bastano a
     * rispondere a «da quando non gira più» e a «quanto ci mette di solito»;
     * oltre, sarebbe una tabella che cresce per sempre senza rispondere a
     * nessuna domanda in più. La purga la fa `model:prune` dallo scheduler.
     */
    'delete_log_items_older_than_days' => 30,

    'date_format' => 'Y-m-d H:i:s',

    'models' => [
        'monitored_scheduled_task' => MonitoredScheduledTask::class,
        'monitored_scheduled_log_item' => MonitoredScheduledTaskLogItem::class,
    ],

    /*
     * Oh Dear è un servizio esterno a pagamento e non fa parte di questo stack:
     * senza `monitor_id` il pacchetto salta da sé ogni comunicazione verso di
     * loro, e nessun dato lascia il server. Le chiavi restano perché il
     * pacchetto le legge comunque — `grace_time_in_minutes`, in particolare, è
     * il margine predefinito che `ScheduledTasksCheck` usa per decidere se un
     * comando è in ritardo, e vale anche senza Oh Dear.
     */
    'oh_dear' => [
        'api_token' => '',
        'monitor_id' => null,
        'queue' => null,
        'ping_oh_dear_job' => PingOhDearJob::class,
        'retry_job_for_minutes' => 10,
        'retry_delay_ms' => 10_000,
        'silence_ping_oh_dear_job_in_horizon' => true,
        'send_starting_ping' => false,

        /*
         * Quanto un comando può tardare prima di essere considerato in
         * ritardo. Cinque minuti è il margine di chi gira ogni cinque minuti o
         * più spesso; chi ha bisogno di più tempo lo dichiara sul proprio
         * `Schedule::command(...)` con `graceTimeInMinutes()`, come fa il
         * backup in `routes/console.php`.
         */
        'grace_time_in_minutes' => 5,

        'endpoint_url' => null,
        'api_url' => 'https://ohdear.app/api/',
        'debug_logging' => false,
    ],

];
