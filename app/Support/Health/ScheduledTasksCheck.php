<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Support\Collection;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\ScheduleMonitor\Support\ScheduledTasks\ScheduledTasks;
use Spatie\ScheduleMonitor\Support\ScheduledTasks\Tasks\Task;

/**
 * L'allarme che rende utile `spatie/laravel-schedule-monitor`.
 *
 * Il pacchetto, da solo, **registra** e non avvisa: scrive in
 * `monitored_scheduled_tasks` quando ogni comando è partito e quando è
 * finito, e chi vuole saperlo deve andarci a guardare. La notifica la porta
 * Oh Dear, che è un servizio esterno a pagamento e non fa parte di questo
 * stack (§12 dello stack tecnologico).
 *
 * Qui i due pacchetti si incontrano: il monitor tiene il registro, questo
 * controllo lo legge e lo traduce in uno stato che `health:check` sa
 * notificare via email. Un `notifications:send` che smette di girare — il caso
 * per cui lo stack ha scelto il pacchetto, «se muore in silenzio nessuno
 * riceve più promemoria» — diventa un messaggio invece di un silenzio.
 *
 * «Smesso di girare» non si deduce dal tempo trascorso, che per un comando
 * mensile sarebbe di trenta giorni e per uno ogni cinque minuti di cinque:
 * lo dice `Task::lastRunFinishedTooLate()`, che confronta l'ultima esecuzione
 * conclusa con la **prossima prevista dalla propria espressione cron**, più un
 * margine di tolleranza.
 */
final class ScheduledTasksCheck extends Check
{
    public function run(): Result
    {
        $tasks = ScheduledTasks::createForSchedule()->uniqueTasks();

        /*
         * Nessun task sorvegliato significa che `schedule-monitor:sync` non è
         * mai stato eseguito, oppure che lo scheduler è vuoto: entrambi i casi
         * sono il guasto stesso, perché il registro su cui si fonda ogni altra
         * risposta non esiste.
         */
        if ($tasks->isEmpty()) {
            return Result::make()
                ->failed(__('health.scheduled_tasks.none'))
                ->shortSummary(__('health.scheduled_tasks.summary_none'));
        }

        /*
         * Un comando che lo scheduler conosce ma di cui il registro non sa
         * nulla è **fuori sorveglianza**, e sarebbe il caso peggiore: tutto
         * verde perché nessuno sta guardando. Succede quando
         * `schedule-monitor:sync` non è stato eseguito dopo un rilascio che ha
         * aggiunto comandi — cioè proprio quando i comandi nuovi sono più
         * fragili.
         */
        $unmonitored = $this->namesOf($tasks->reject(static fn (Task $task): bool => $task->isBeingMonitored()));

        if ($unmonitored !== []) {
            return Result::make()
                ->failed(__('health.scheduled_tasks.unmonitored', ['tasks' => implode(', ', $unmonitored)]))
                ->shortSummary(__('health.scheduled_tasks.summary_unmonitored', ['count' => count($unmonitored)]))
                ->meta([
                    'monitored' => $tasks->count() - count($unmonitored),
                    'unmonitored' => implode(', ', $unmonitored),
                ]);
        }

        $failed = $this->namesOf($tasks->filter(static fn (Task $task): bool => $task->lastRunFailed()));
        $late = $this->namesOf($tasks->filter(static fn (Task $task): bool => $task->lastRunFinishedTooLate()));

        /*
         * Un task che è insieme fallito e in ritardo si racconta una volta
         * sola, come fallito: è la stessa notizia, e il motivo è più preciso.
         */
        $late = array_values(array_diff($late, $failed));

        $result = Result::make()->meta([
            'monitored' => $tasks->count(),
            'failed' => implode(', ', $failed),
            'late' => implode(', ', $late),
        ]);

        if ($failed !== []) {
            return $result
                ->failed(__('health.scheduled_tasks.failed', ['tasks' => implode(', ', $failed)]))
                ->shortSummary(__('health.scheduled_tasks.summary_failed', ['count' => count($failed)]));
        }

        if ($late !== []) {
            return $result
                ->failed(__('health.scheduled_tasks.late', ['tasks' => implode(', ', $late)]))
                ->shortSummary(__('health.scheduled_tasks.summary_late', ['count' => count($late)]));
        }

        return $result
            ->ok(__('health.scheduled_tasks.ok', ['count' => $tasks->count()]))
            ->shortSummary(__('health.scheduled_tasks.summary_ok', ['count' => $tasks->count()]));
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return array<int, string>
     */
    private function namesOf(Collection $tasks): array
    {
        return $tasks
            ->map(static fn (Task $task): string => $task->name())
            ->values()
            ->all();
    }
}
