<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Enums\NotificationStatus;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Il resoconto del worker di §15.5.
 *
 * §15.5 chiede che ogni invio previsto sia «visibile e verificabile». Il
 * riepilogo che il worker restituisce è metà di quella visibilità: è la sola
 * riga che chi guarda il cron o il log vede passare, ed è quella su cui si
 * decide se qualcosa è andato storto stanotte. Un riepilogo che dice sempre
 * zero è peggio di nessun riepilogo, perché rassicura.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');

    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');

    $this->user = User::factory()->create();
    app(SaveOccurrences::class)->one($this->user, $this->occurrence);

    Carbon::setTestNow(localInstant($this->city, '2026-09-05 18:30'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('conta davvero quello che ha fatto', function (): void {
    Notification::fake();

    expect(app(NotificationDispatcher::class)->run())
        ->toBe(['claimed' => 2, 'sent' => 2, 'skipped' => 0, 'deferred' => 0, 'failed' => 0]);
});

it('lo dice al comando, invece di annunciare che non c era niente da fare', function (): void {
    Notification::fake();

    $this->artisan('notifications:send')
        ->expectsOutput(__('console.notifications_send.done', [
            'claimed' => 2,
            'sent' => 2,
            'skipped' => 0,
            'deferred' => 0,
            'failed' => 0,
        ]))
        ->assertSuccessful();
});

it('dice che non c è niente da fare solo quando non c è', function (): void {
    Notification::fake();

    ScheduledNotification::query()->update(['status' => NotificationStatus::Cancelled->value]);

    $this->artisan('notifications:send')
        ->expectsOutput(__('console.notifications_send.empty'))
        ->assertSuccessful();
});

/**
 * `--limit` esiste per «guardare cosa succede su poche righe prima di
 * lasciarlo andare su tutte»: se non limitasse, quel gesto prudente
 * spedirebbe tutto.
 */
it('si ferma davvero al numero di righe chiesto con --limit', function (): void {
    Notification::fake();

    $this->artisan('notifications:send', ['--limit' => 1])->assertSuccessful();

    expect(ScheduledNotification::query()->ofStatus(NotificationStatus::Sent)->count())->toBe(1)
        ->and(ScheduledNotification::query()->pending()->count())->toBe(1);
});
