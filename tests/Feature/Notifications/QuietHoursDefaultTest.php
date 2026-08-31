<?php

declare(strict_types=1);

use App\DTOs\QuietHours;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Services\Notifications\NotificationGate;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Le ore di silenzio di chi non le ha impostate (D36).
 *
 * Prima non esisteva alcun valore predefinito e un promemoria poteva partire
 * alle tre di notte verso chi non aveva mai aperto le preferenze. Ora vale
 * 23:00-08:00 per chi non ha scelto, e resta possibile non averne affatto —
 * ma dicendolo, non tacendo.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

it('applica la finestra predefinita a chi non ha scelto', function (): void {
    $user = User::factory()->make(['quiet_hours' => null]);

    $quiet = QuietHours::fromUser($user);

    expect($quiet)->not->toBeNull()
        ->and($quiet->toArray())->toBe(['from' => '23:00', 'to' => '08:00'])
        ->and($quiet->contains(CarbonImmutable::parse('2026-09-10 03:00', 'Europe/Rome')))->toBeTrue()
        ->and($quiet->contains(CarbonImmutable::parse('2026-09-10 12:00', 'Europe/Rome')))->toBeFalse();
});

it('preferisce le ore scelte alla finestra predefinita', function (): void {
    $user = User::factory()->make(['quiet_hours' => ['from' => '21:00', 'to' => '06:00']]);

    expect(QuietHours::fromUser($user)?->toArray())->toBe(['from' => '21:00', 'to' => '06:00']);
});

it('lascia senza silenzio chi lo ha rifiutato dichiarandolo', function (): void {
    // Il terzo stato: un array vuoto è una scelta, `null` è la sua assenza.
    // Senza questa differenza il valore predefinito non si potrebbe spegnere.
    expect(QuietHours::fromUser(User::factory()->make(['quiet_hours' => []])))->toBeNull()
        ->and(QuietHours::fromUser(User::factory()->make(['quiet_hours' => ['from' => '08:00', 'to' => '08:00']])))->toBeNull();
});

it('torna al comportamento di prima se la configurazione non dichiara una finestra', function (): void {
    config()->set('notifications.quiet_hours.default', null);

    expect(QuietHours::fromUser(User::factory()->make(['quiet_hours' => null])))->toBeNull();
});

it('non parla di silenzio quando le ore di silenzio sono spente del tutto', function (): void {
    config()->set('notifications.quiet_hours.enabled', false);

    expect(QuietHours::fromUser(User::factory()->make(['quiet_hours' => null])))->toBeNull()
        ->and(QuietHours::fromUser(User::factory()->make(['quiet_hours' => ['from' => '23:00', 'to' => '08:00']])))->toBeNull();
});

it('rimanda alle otto un promemoria notturno per chi non ha scelto', function (): void {
    $user = User::factory()->create(['quiet_hours' => null, 'timezone' => 'Europe/Rome']);

    $notification = new ScheduledNotification([
        'type' => NotificationType::EventReminder->value,
        'status' => NotificationStatus::Pending->value,
    ]);

    $now = CarbonImmutable::parse('2026-09-10 03:00', 'Europe/Rome');

    $decision = app(NotificationGate::class)->decide($notification, $user, $now->utc());

    expect($decision->deferTo?->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2026-09-10 08:00');
});

it('non rimanda nulla a chi ha dichiarato di non volere silenzio', function (): void {
    $user = User::factory()->create(['quiet_hours' => [], 'timezone' => 'Europe/Rome']);

    $notification = new ScheduledNotification([
        'type' => NotificationType::EventReminder->value,
        'status' => NotificationStatus::Pending->value,
    ]);

    $now = CarbonImmutable::parse('2026-09-10 03:00', 'Europe/Rome');

    $decision = app(NotificationGate::class)->decide($notification, $user, $now->utc());

    expect($decision->deferTo)->toBeNull();
});

it('non ferma comunque gli avvisi di annullamento, che non conoscono silenzio', function (): void {
    $user = User::factory()->create(['quiet_hours' => null, 'timezone' => 'Europe/Rome']);

    $notification = new ScheduledNotification([
        'type' => NotificationType::EventCancelled->value,
        'status' => NotificationStatus::Pending->value,
    ]);

    $now = CarbonImmutable::parse('2026-09-10 03:00', 'Europe/Rome');

    $decision = app(NotificationGate::class)->decide($notification, $user, $now->utc());

    expect($decision->deferTo)->toBeNull();
});

describe('la scelta si esprime dal profilo', function (): void {
    it('mostra la finestra predefinita a chi non ha ancora scelto', function (): void {
        testCity();

        $user = User::factory()->create(['quiet_hours' => null]);

        expect(QuietHours::formFor($user))->toBe(['from' => '23:00', 'to' => '08:00', 'off' => false]);

        $this->actingAs($user)->get('/il-mio-profilo')
            ->assertOk()
            ->assertSee('value="23:00"', escape: false)
            ->assertSee(__('account.profile.quiet_off'));
    });

    it('registra il rifiuto come scelta e non come assenza di scelta', function (): void {
        testCity();

        $user = User::factory()->create(['quiet_hours' => ['from' => '23:00', 'to' => '08:00']]);

        $this->actingAs($user)->patch('/il-mio-profilo', [
            'timezone' => 'Europe/Rome',
            'locale' => 'it',
            'quiet_off' => '1',
        ])->assertRedirect();

        expect($user->refresh()->quiet_hours)->toBe([])
            ->and(QuietHours::fromUser($user))->toBeNull()
            ->and(QuietHours::formFor($user))->toBe(['from' => '23:00', 'to' => '08:00', 'off' => true]);
    });

    it('salva le ore scritte a mano', function (): void {
        testCity();

        $user = User::factory()->create(['quiet_hours' => null]);

        $this->actingAs($user)->patch('/il-mio-profilo', [
            'timezone' => 'Europe/Rome',
            'locale' => 'it',
            'quiet_from' => '22:30',
            'quiet_to' => '07:15',
        ])->assertRedirect();

        expect($user->refresh()->quiet_hours)->toBe(['from' => '22:30', 'to' => '07:15']);
    });
});

describe('l API dichiara la scelta e ciò che vale davvero', function (): void {
    it('affianca la finestra applicata alla scelta di chi non ha scelto', function (): void {
        testCity();

        $user = User::factory()->create(['quiet_hours' => null]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.quiet_hours', null)
            ->assertJsonPath('data.quiet_hours_effective', ['from' => '23:00', 'to' => '08:00']);
    });

    it('accetta un oggetto vuoto come rifiuto esplicito del silenzio', function (): void {
        testCity();

        $user = User::factory()->create(['quiet_hours' => ['from' => '23:00', 'to' => '08:00']]);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/me', ['quiet_hours' => []])
            ->assertOk();

        expect($user->refresh()->quiet_hours)->toBe([]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.quiet_hours_effective', null);
    });
});
