<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Enums\NotificationStatus;
use App\Enums\UserRole;
use App\Models\ScheduledNotification;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Gli invii previsti di §15.5 non sono dati di servizio: ogni riga dice
 * l'indirizzo di una persona, che cosa ha messo in agenda e quando lo saprà.
 * Chi può guardarli è scritto in `ScheduledNotificationPolicy`; qui si verifica
 * che non esista **nessun'altra strada** per arrivarci.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');

    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-05 21:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('non fa vedere a una persona gli invii previsti di un altra', function (): void {
    $io = User::factory()->create();
    $altro = User::factory()->create();

    app(SaveOccurrences::class)->one($altro, $this->occurrence);

    expect(ScheduledNotification::query()->where('user_id', $altro->getKey())->count())->toBe(2);

    /* L'unico archivio esposto è quello in-app, e contiene solo il proprio. */
    $risposta = $this->withToken($io->createToken('Telefono')->plainTextToken)
        ->getJson('/api/v1/me/notifications')
        ->assertOk();

    expect($risposta->json('data'))->toBe([]);
});

it('non espone gli invii previsti su nessuna rotta pubblica o di API', function (): void {
    $percorsi = collect(Route::getRoutes()->getRoutes())
        ->map(static fn (Illuminate\Routing\Route $route): string => $route->uri())
        ->filter(static fn (string $uri): bool => ! str_starts_with($uri, 'admin'))
        ->filter(static fn (string $uri): bool => str_contains($uri, 'scheduled') || str_contains($uri, 'programmate'))
        ->values()
        ->all();

    expect($percorsi)->toBe([]);
});

it('chiude a un utente semplice la pagina del pannello che li elenca', function (): void {
    $user = User::factory()->create();
    $user->assignRole(UserRole::User->value);

    $this->actingAs($user)->get('/admin/scheduled-notifications')->assertForbidden();
});

it('li fa guardare al moderatore senza lasciargliene fermare uno', function (): void {
    $moderatore = User::factory()->create();
    $moderatore->assignRole(UserRole::Moderator->value);

    $user = User::factory()->create();
    app(SaveOccurrences::class)->one($user, $this->occurrence);

    $riga = ScheduledNotification::query()->where('user_id', $user->getKey())->firstOrFail();

    expect($moderatore->can('viewAny', ScheduledNotification::class))->toBeTrue()
        ->and($moderatore->can('cancel', $riga))->toBeFalse();

    $this->actingAs($moderatore)->get('/admin/scheduled-notifications')->assertOk();
});

it('lascia fermare solo ciò che non è ancora partito, e solo a chi ne ha il permesso', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);

    $user = User::factory()->create();
    app(SaveOccurrences::class)->one($user, $this->occurrence);

    $riga = ScheduledNotification::query()->where('user_id', $user->getKey())->firstOrFail();

    expect($admin->can('cancel', $riga))->toBeTrue();

    $riga->forceFill(['status' => NotificationStatus::Sent, 'sent_at' => Carbon::now()])->save();

    expect($admin->can('cancel', $riga->fresh()))->toBeFalse()
        ->and($admin->can('update', $riga->fresh()))->toBeFalse()
        ->and($admin->can('delete', $riga->fresh()))->toBeFalse()
        ->and($admin->can('create', ScheduledNotification::class))->toBeFalse();
});

/**
 * L'archivio in-app è l'altra metà della stessa promessa: due persone, due
 * archivi, nessuna sovrapposizione. Il cursore di paginazione non è una chiave
 * per entrare in quello altrui.
 */
it('tiene separati i due archivi in-app anche a paginazione avviata', function (): void {
    $io = User::factory()->create();
    $altro = User::factory()->create();

    foreach ([$io, $altro] as $indice => $persona) {
        $persona->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'prova',
            'data' => ['messaggio' => 'archivio di '.$indice],
            'read_at' => null,
        ]);
    }

    $risposta = $this->withToken($io->createToken('Telefono')->plainTextToken)
        ->getJson('/api/v1/me/notifications?limit=1')
        ->assertOk();

    expect($risposta->json('data'))->toHaveCount(1)
        ->and(json_encode($risposta->json()))->toContain('archivio di 0')
        ->and(json_encode($risposta->json()))->not->toContain('archivio di 1');
});
