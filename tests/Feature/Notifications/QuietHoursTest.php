<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Actions\Account\SaveOccurrences;
use App\DTOs\QuietHours;
use App\Enums\NotificationStatus;
use App\Models\ScheduledNotification;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * Le ore di silenzio (§15.4) e la cancellazione dell'account (§15.2), che sul
 * motore delle notifiche hanno lo stesso effetto visibile: qualcosa che era
 * previsto non parte più.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

it('riconosce una finestra che attraversa la mezzanotte', function (string $time, bool $inside): void {
    $user = User::factory()->make(['quiet_hours' => ['from' => '23:30', 'to' => '08:00']]);
    $quiet = QuietHours::fromUser($user);

    expect($quiet)->not->toBeNull()
        ->and($quiet->contains(CarbonImmutable::parse('2026-09-10 '.$time, 'Europe/Rome')))->toBe($inside);
})->with([
    'poco prima' => ['23:29', false],
    'l\'istante d\'inizio' => ['23:30', true],
    'nel cuore della notte' => ['03:00', true],
    'l\'istante di fine' => ['08:00', false],
    'a metà mattina' => ['10:00', false],
]);

it('dà a chi non ha scelto la finestra predefinita, e nessuna a chi l ha rifiutata', function (): void {
    // D36: chi non ha scelto riceve 23:00-08:00, perché l'omissione non deve
    // tradursi in un promemoria alle tre di notte. Chi non ne vuole affatto lo
    // dichiara, e allora la colonna contiene un array vuoto — non `null`.
    expect(QuietHours::fromUser(User::factory()->make(['quiet_hours' => null]))?->toArray())
        ->toBe(['from' => '23:00', 'to' => '08:00'])
        ->and(QuietHours::fromUser(User::factory()->make(['quiet_hours' => []])))->toBeNull()
        ->and(QuietHours::fromUser(User::factory()->make(['quiet_hours' => ['from' => '08:00', 'to' => '08:00']])))->toBeNull();
});

it('esce dal silenzio all ora giusta anche nella notte in cui cambia l ora', function (): void {
    // Ultima domenica di ottobre 2026: la notte dura venticinque ore. Sommare
    // secondi alla mezzanotte darebbe le 07:00.
    $user = User::factory()->make(['quiet_hours' => ['from' => '23:30', 'to' => '08:00']]);
    $quiet = QuietHours::fromUser($user);

    $inside = CarbonImmutable::parse('2026-10-24 23:45', 'Europe/Rome');

    expect($quiet?->endsAfter($inside)->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2026-10-25 08:00');
});

it('spegne gli invii previsti quando l account viene cancellato', function (): void {
    // §15.2: «eliminazione account self-service, con anonimizzazione ed
    // effetto immediato sui salvataggi e sulle notifiche programmate».
    $city = testCity();
    $category = testCategory();
    freezeLocal($city, '2026-09-01 12:00');

    Notification::fake();

    $occurrence = occurrenceAtLocal($city, $category, '2026-09-05 21:00');
    $user = User::factory()->create();

    app(SaveOccurrences::class)->one($user, $occurrence);

    expect(ScheduledNotification::query()->pending()->count())->toBe(2);

    app(DeleteAccount::class)($user);

    expect(ScheduledNotification::query()->pending()->count())->toBe(0)
        ->and(ScheduledNotification::query()->ofStatus(NotificationStatus::Cancelled)->count())->toBe(2);

    Carbon::setTestNow(localInstant($city, '2026-09-05 20:00'));
    $this->artisan('notifications:send');

    Notification::assertNothingSent();
});
