<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;

final class SchedulerOverview
{
    /** @return list<array<string, mixed>> */
    public function tasks(): array
    {
        // Loading the console kernel also registers routes/console.php on HTTP requests.
        Artisan::call('schedule:list', ['--json' => true, '--timezone' => 'UTC']);
        $tasks = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $monitored = MonitoredScheduledTask::query()->get()->keyBy('name');
        foreach ($tasks as &$task) {
            $command = preg_replace('/^.*artisan[\'"]?\s*/', '', $task['command']) ?? $task['command'];
            $name = $task['description'] ?: $command;
            $task['name'] = $name;
            $task['explanation'] = $this->explanation($name);
            $record = $monitored->get($name);
            $task['last_finished'] = $record?->last_finished_at?->utc()->format('d/m/Y H:i:s');
            $task['last_failed'] = $record?->last_failed_at?->utc()->format('d/m/Y H:i:s');
            $task['monitored'] = $record !== null;
        }

        return $tasks;
    }

    /** @return array{scheduler:string,worker:string} */
    public function cron(): array
    {
        $path = escapeshellarg(base_path());
        $php = escapeshellarg(config()->string('scheduler.php_binary'));
        $scheduleLog = escapeshellarg(storage_path('logs/schedule.log'));
        $queueLog = escapeshellarg(storage_path('logs/queue.log'));
        $scheduleLock = escapeshellarg(storage_path('framework/scheduler-cron.lock'));
        $queueLock = escapeshellarg(storage_path('framework/queue-cron.lock'));

        return [
            'scheduler' => "* * * * * cd {$path} && /usr/bin/flock -n {$scheduleLock} {$php} artisan schedule:run >> {$scheduleLog} 2>&1",
            'worker' => "* * * * * cd {$path} && /usr/bin/flock -n {$queueLock} {$php} artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> {$queueLog} 2>&1",
        ];
    }

    /** @return array{readable:bool,scheduler:bool,worker:bool} */
    public function installed(): array
    {
        try {
            $result = Process::timeout(3)->run(['crontab', '-l']);
            if (! $result->successful()) {
                return ['readable' => false, 'scheduler' => false, 'worker' => false];
            }
            $lines = collect(explode("\n", $result->output()))->filter(fn (string $line): bool => ! str_starts_with(trim($line), '#') && str_contains($line, base_path()));

            return ['readable' => true, 'scheduler' => $lines->contains(fn (string $line): bool => str_contains($line, 'artisan schedule:run')),
                'worker' => $lines->contains(fn (string $line): bool => str_contains($line, 'artisan queue:work'))];
        } catch (\Throwable) {
            return ['readable' => false, 'scheduler' => false, 'worker' => false];
        }
    }

    private function explanation(string $name): string
    {
        return match (true) {
            str_starts_with($name, 'events:publish-due') => 'Pubblica ogni minuto gli eventi programmati arrivati a scadenza. Ricontrolla i permessi del richiedente: senza pubblicazione autonoma autorizzata il locale resta in attesa degli admin. Rifiuti e annullamenti impediscono la pubblicazione.',
            str_starts_with($name, 'social:publish-due') => 'Invia alla coda i post social programmati la cui data è arrivata. Il worker verifica i dati e pubblica sui canali Meta e Telegram abilitati.',
            str_starts_with($name, 'social:daily') => 'Prepara il carosello giornaliero all’orario scelto in Social → Impostazioni, nel fuso della città. Richiede collegamento verificato e automatismo attivo.',
            str_starts_with($name, 'ticketing:promote') => 'Promuove le prenotazioni in lista d’attesa quando si apre la finestra prevista.',
            str_starts_with($name, 'google-calendar:sync') => 'Accoda la sincronizzazione dei calendari Google collegati e abilitati.',
            str_starts_with($name, 'queue:work google_calendar') => 'Consuma la coda Google Calendar con timeout dedicato. Già avviato dallo scheduler.',
            str_starts_with($name, 'occurrences:generate') => 'Estende le date degli eventi ricorrenti fino all’orizzonte configurato.',
            str_starts_with($name, 'import:run') => 'Controlla ogni cinque minuti, accoda gli import una volta all’ora; ogni sorgente mantiene i propri filtri.',
            str_starts_with($name, 'notifications:send') => 'Invia le notifiche giunte a scadenza, rispettando preferenze e deduplicazione.',
            str_starts_with($name, 'notifications:plan') => 'Pianifica digest e riepiloghi una volta all’ora e applica la conservazione dello storico.',
            str_starts_with($name, 'events:archive') => 'Archivia gli eventi con tutte le date scadute oltre il periodo di tolleranza.',
            str_starts_with($name, 'backup:run') => 'Esegue il backup giornaliero solo se lo spazio disponibile è sufficiente.',
            str_starts_with($name, 'backup:clean') => 'Elimina le copie oltre la conservazione configurata.',
            str_starts_with($name, 'backup:monitor') => 'Controlla età e dimensione dei backup e segnala anomalie.',
            str_starts_with($name, 'health:check') => 'Aggiorna i controlli di salute del sito e gli eventuali allarmi.',
            str_starts_with($name, 'health:queue-check-heartbeat') => 'Accoda il battito che consente di rilevare un worker fermo.',
            str_starts_with($name, 'health:schedule-check-heartbeat') => 'Registra il battito dello scheduler per rilevare interruzioni del cron.',
            str_starts_with($name, 'schedule-monitor:sync') => 'Allinea il monitor alle attività realmente registrate, comprese quelle nuove.',
            str_starts_with($name, 'model:prune') => 'Applica la conservazione dei dati al modello indicato nel comando.',
            str_starts_with($name, 'telescope:prune') => 'Rimuove la diagnostica Telescope più vecchia del periodo indicato.',
            str_starts_with($name, 'deploy:pull') => 'Controlla la disponibilità di un rilascio con asset e test verificati, poi aggiorna il sito.',
            str_starts_with($name, 'sponsorships:report') => 'Prepara i rapporti settimanali per gli inserzionisti delle campagne.',
            str_starts_with($name, 'sponsorship-grants:sync') => 'Allinea le campagne alle abilitazioni pubblicitarie attive e alle loro scadenze.',
            default => 'Attività registrata nello scheduler applicativo. La frequenza e il comando sono quelli effettivamente in uso.',
        };
    }
}
