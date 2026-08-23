<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Enums\NotificationStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\ScheduledNotifications\ScheduledNotificationResource;
use App\Models\ScheduledNotification;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Gate;

/**
 * §15.5: «la tabella rende ogni invio previsto **visibile e verificabile in
 * anticipo** dal pannello admin: un job in coda ritardato non lo è».
 *
 * Questa pagina è quindi parte del motore, non un accessorio: senza, la scelta
 * di usare una tabella invece di una coda non avrebbe più alcun vantaggio.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');

    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
    $this->saver = User::factory()->create(['email' => 'chi.salva@example.test']);

    app(SaveOccurrences::class)->one($this->saver, $this->occurrence);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::SuperAdmin->value);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('mostra gli invii previsti senza chiavi di traduzione grezze', function (): void {
    $response = $this->actingAs($this->admin)->get(ScheduledNotificationResource::getUrl('index'));

    $response->assertOk()
        ->assertSee('chi.salva@example.test')
        ->assertSee(__('enums.notification_type.event_reminder'));

    expect(preg_match('/\b(?:admin|enums|common)\.[a-z_]+\.[a-z_.]+\b/', $response->getContent()))->toBe(0);
});

it('lascia guardare al moderatore e fermare solo a chi amministra', function (): void {
    $moderator = User::factory()->create();
    $moderator->assignRole(UserRole::Moderator->value);

    $row = ScheduledNotification::query()->firstOrFail();

    expect(Gate::forUser($moderator)->allows('viewAny', ScheduledNotification::class))->toBeTrue()
        ->and(Gate::forUser($moderator)->allows('cancel', $row))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('cancel', $row))->toBeTrue();

    $this->actingAs($moderator)->get(ScheduledNotificationResource::getUrl('index'))->assertOk();
});

it('non lascia annullare ciò che è già partito', function (): void {
    $row = ScheduledNotification::query()->firstOrFail();
    $row->markSent(Carbon::now()->toImmutable());

    expect(Gate::forUser($this->admin)->allows('cancel', $row->fresh()))->toBeFalse();
});

it('non lascia creare né modificare una riga dal pannello', function (): void {
    $row = ScheduledNotification::query()->firstOrFail();

    expect(Gate::forUser($this->admin)->allows('create', ScheduledNotification::class))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('update', $row))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('delete', $row))->toBeFalse()
        ->and(ScheduledNotificationResource::canCreate())->toBeFalse();
});

it('ferma davvero un invio, lasciandone la traccia', function (): void {
    $row = ScheduledNotification::query()->firstOrFail();
    $row->markCancelled();

    expect($row->fresh()?->status)->toBe(NotificationStatus::Cancelled)
        ->and(ScheduledNotification::query()->whereKey($row->getKey())->exists())->toBeTrue();
});

it('tiene il conto degli invii scaduti e non ancora partiti', function (): void {
    // Zero è la risposta normale. Un numero che cresce significa che il
    // worker dei cinque minuti non sta girando: è l'unico guasto di questo
    // motore che nessun log racconterebbe da solo.
    expect(ScheduledNotificationResource::getNavigationBadge())->toBeNull();

    Carbon::setTestNow(localInstant($this->city, '2026-09-05 20:00'));

    expect(ScheduledNotificationResource::getNavigationBadge())->toBe('2');
});
