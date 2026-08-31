<?php

declare(strict_types=1);

use App\Support\Health\ScheduledTasksCheck;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\Health\Enums\Status;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;

/**
 * L'allarme che rende utile `spatie/laravel-schedule-monitor` (§12 dello
 * stack: «il worker delle notifiche è critico, se muore in silenzio nessuno
 * riceve più promemoria»).
 *
 * Lo scheduler vero della applicazione è quello di `routes/console.php`: qui
 * se ne dichiara uno con un comando solo, perché il test deve verificare come
 * il controllo legge il registro, non quali comandi ci siano dentro.
 */
function scheduleConSoloNotifiche(): void
{
    app()->forgetInstance(Schedule::class);

    $schedule = app(Schedule::class);
    $schedule->command('notifications:send')->everyFiveMinutes();

    app()->instance(Schedule::class, $schedule);
}

beforeEach(function (): void {
    scheduleConSoloNotifiche();
});

/**
 * Il caso peggiore: tutto verde perché nessuno sta guardando. Succede quando
 * `schedule-monitor:sync` non è stato eseguito dopo un rilascio.
 */
it('è rosso quando i comandi non sono sorvegliati', function (): void {
    $result = ScheduledTasksCheck::new()->run();

    expect($result->status->value)->toBe(Status::failed()->value)
        ->and($result->getNotificationMessage())->toContain('notifications:send')
        ->and($result->getNotificationMessage())->toContain('schedule-monitor:sync');
});

it('sta bene quando ogni comando ha girato nei tempi', function (): void {
    $this->artisan('schedule-monitor:sync')->assertSuccessful();

    MonitoredScheduledTask::query()->update([
        'last_started_at' => CarbonImmutable::now()->subMinute(),
        'last_finished_at' => CarbonImmutable::now()->subMinute(),
    ]);

    expect(ScheduledTasksCheck::new()->run()->status->value)->toBe(Status::ok()->value);
});

/**
 * «Smesso di girare» non è un tempo assoluto: è l'ora della prossima
 * esecuzione prevista dalla propria espressione cron, più la tolleranza.
 */
it('è rosso quando un comando ha smesso di girare', function (): void {
    $this->artisan('schedule-monitor:sync')->assertSuccessful();

    MonitoredScheduledTask::query()->update([
        'last_started_at' => CarbonImmutable::now()->subHours(6),
        'last_finished_at' => CarbonImmutable::now()->subHours(6),
    ]);

    $result = ScheduledTasksCheck::new()->run();

    expect($result->status->value)->toBe(Status::failed()->value)
        ->and($result->getNotificationMessage())->toContain('notifications:send')
        ->and($result->getShortSummary())->toBe(__('health.scheduled_tasks.summary_late', ['count' => 1]));
});

it('è rosso quando l\'ultima esecuzione di un comando è fallita', function (): void {
    $this->artisan('schedule-monitor:sync')->assertSuccessful();

    MonitoredScheduledTask::query()->update([
        'last_started_at' => CarbonImmutable::now()->subMinute(),
        'last_finished_at' => CarbonImmutable::now()->subMinute(),
        'last_failed_at' => CarbonImmutable::now()->subSeconds(30),
    ]);

    $result = ScheduledTasksCheck::new()->run();

    expect($result->status->value)->toBe(Status::failed()->value)
        ->and($result->getNotificationMessage())->toBe(
            __('health.scheduled_tasks.failed', ['tasks' => 'notifications:send']),
        );
});

it('è rosso quando lo scheduler è vuoto', function (): void {
    app()->forgetInstance(Schedule::class);
    app()->instance(Schedule::class, app(Schedule::class));

    $result = ScheduledTasksCheck::new()->run();

    expect($result->status->value)->toBe(Status::failed()->value)
        ->and($result->getNotificationMessage())->toBe(__('health.scheduled_tasks.none'));
});
