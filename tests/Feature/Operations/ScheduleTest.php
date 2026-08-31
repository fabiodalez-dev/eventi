<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;
use Spatie\ScheduleMonitor\Support\ScheduledTasks\ScheduledTasks;
use Spatie\ScheduleMonitor\Support\ScheduledTasks\Tasks\Task;

/**
 * Lo scheduler vero, quello di `routes/console.php`. Un comando dichiarato
 * nella configurazione ma non messo in calendario è la forma di guasto più
 * silenziosa che ci sia: tutto sembra a posto, e non gira niente.
 *
 * @return array<string, string>
 */
function comandiSchedulati(): array
{
    $espressioni = [];

    foreach (app(Schedule::class)->events() as $event) {
        /** @var Event $event */
        $espressioni[comandoDi($event)] = $event->getExpression();
    }

    return $espressioni;
}

function comandoDi(Event $event): string
{
    $comando = (string) $event->command;

    return trim(preg_replace('/^.*artisan[\'"]?\s*/', '', $comando) ?? $comando);
}

it('mette il backup in calendario ogni giorno', function (): void {
    $comandi = comandiSchedulati();

    expect($comandi)->toHaveKey('backup:run')
        ->and($comandi['backup:run'])->toBe('40 3 * * *')
        ->and($comandi)->toHaveKey('backup:clean')
        ->and($comandi)->toHaveKey('backup:monitor');
});

/**
 * La pulizia deve girare **dopo** il backup del giorno: al contrario, la copia
 * più recente sarebbe quella di ieri e la conservazione scivolerebbe di un
 * giorno.
 */
it('pulisce dopo aver fatto la copia', function (): void {
    $comandi = comandiSchedulati();

    [$minutoBackup, $oraBackup] = explode(' ', $comandi['backup:run']);
    [$minutoPulizia, $oraPulizia] = explode(' ', $comandi['backup:clean']);

    expect((int) $oraPulizia * 60 + (int) $minutoPulizia)
        ->toBeGreaterThan((int) $oraBackup * 60 + (int) $minutoBackup);
});

it('fa girare i controlli di stato e i loro battiti', function (): void {
    $comandi = comandiSchedulati();

    expect($comandi['health:check'])->toBe('*/5 * * * *')
        ->and($comandi['health:queue-check-heartbeat'])->toBe('* * * * *')
        ->and($comandi['health:schedule-check-heartbeat'])->toBe('* * * * *');
});

/**
 * Il registro del monitor si allinea da solo: senza, un comando aggiunto in
 * `routes/console.php` resta fuori dalla sorveglianza fino al rilascio
 * successivo — cioè proprio nei giorni in cui è più probabile che non funzioni.
 */
it('riallinea da solo il registro dei comandi sorvegliati', function (): void {
    expect(comandiSchedulati())->toHaveKey('schedule-monitor:sync');
});

it('sorveglia i comandi che contano', function (): void {
    $this->artisan('schedule-monitor:sync')->assertSuccessful();

    $sorvegliati = MonitoredScheduledTask::query()->pluck('name')->all();

    expect($sorvegliati)->toContain('notifications:send')
        ->and($sorvegliati)->toContain('import:run')
        ->and($sorvegliati)->toContain('backup:run')
        ->and($sorvegliati)->toContain('health:check');
});

/**
 * I due battiti girano ogni minuto e scriverebbero da soli due terzi dello
 * storico, per dire una cosa che `ScheduleCheck` e `QueueCheck` già dicono
 * meglio. Sorvegliare il sorvegliante costa e non aggiunge nulla.
 */
it('lascia fuori dalla sorveglianza i due battiti', function (): void {
    $this->artisan('schedule-monitor:sync')->assertSuccessful();

    $sorvegliati = MonitoredScheduledTask::query()->pluck('name')->all();

    expect($sorvegliati)->not->toContain('health:queue-check-heartbeat')
        ->and($sorvegliati)->not->toContain('health:schedule-check-heartbeat');
});

/**
 * Il backup non gira in background: lo scheduler non conoscerebbe il codice di
 * uscita, e il monitor registrerebbe come riuscita ogni esecuzione, fallimenti
 * compresi. Un backup che fallisce in silenzio è ciò che §16 vuole impedire.
 */
it('non manda il backup in background', function (): void {
    $backup = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => comandoDi($event) === 'backup:run');

    expect($backup)->not->toBeNull()
        ->and($backup->runInBackground)->toBeFalse();
});

it('concede al backup un margine più lungo degli altri comandi', function (): void {
    $tasks = ScheduledTasks::createForSchedule()->uniqueTasks();

    $backup = $tasks->first(fn (Task $task): bool => $task->name() === 'backup:run');
    $notifiche = $tasks->first(fn (Task $task): bool => $task->name() === 'notifications:send');

    expect($backup->graceTimeInMinutes())->toBe(120)
        ->and($notifiche->graceTimeInMinutes())->toBe(5);
});
