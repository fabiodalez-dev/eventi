<?php

declare(strict_types=1);

use App\Enums\VenueStatus;
use App\Filament\Auth\Login;
use App\Models\City;
use App\Models\MobileAuthChallenge;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\MagicLoginLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

beforeEach(function (): void {
    testCity();
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
});

it('rejects invalid registration without creating accounts or tokens', function (string $path, array $override, string $field): void {
    $data = array_replace(['email' => 'new@example.test', 'password' => 'A-valid-password-123', 'password_confirmation' => 'A-valid-password-123'], $override);
    $this->postJson($path, $data)->assertUnprocessable();
    expect(User::query()->where('email', 'new@example.test')->exists())->toBeFalse();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Notification::assertNothingSent();
    $this->assertGuest();
})->with(['/registrati', '/api/v1/auth/register'])->with([
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'mismatched confirmation' => [['password_confirmation' => 'different'], 'password'],
    'array injection' => [['email' => ['new@example.test']], 'email'],
]);

it('preserves an existing account when duplicate registration is attempted', function (string $path): void {
    $user = User::factory()->create(['email' => 'existing@example.test', 'password' => Hash::make('original-password')]);
    $this->postJson($path, ['email' => $user->email, 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])->assertUnprocessable();
    expect(User::query()->count())->toBe(1)->and(Hash::check('original-password', $user->fresh()->password))->toBeTrue();
    Notification::assertNothingSent();
})->with(['/registrati', '/api/v1/auth/register']);

it('ignores privilege and verification fields during registration', function (string $path): void {
    $response = $this->postJson($path, [
        'email' => 'new@example.test', 'password' => 'A-valid-password-123', 'password_confirmation' => 'A-valid-password-123',
        'roles' => ['super_admin'], 'role' => 'super_admin', 'email_verified_at' => now()->toISOString(), 'id' => 9999,
    ]);
    expect($response->status())->toBeIn([201, 302]);
    $user = User::query()->where('email', 'new@example.test')->sole();
    expect($user->hasRole('user'))->toBeTrue()->and($user->hasRole('super_admin'))->toBeFalse()->and($user->email_verified_at)->toBeNull();
})->with(['/registrati', '/api/v1/auth/register']);

it('rotates the browser session on login and closes access after logout', function (): void {
    $user = User::factory()->create();
    $this->get('/accedi')->assertOk();
    $before = session()->getId();
    $this->post('/accedi', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    expect(session()->getId())->not->toBe($before);
    $this->get('/il-mio-profilo')->assertOk();
    $this->post('/esci')->assertRedirect();
    $this->get('/il-mio-profilo')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('does not authenticate or issue tokens for deleted accounts', function (string $path): void {
    $user = User::factory()->create();
    $user->delete();
    $response = $this->postJson($path, ['email' => $user->email, 'password' => 'password']);
    expect($response->status())->toBeIn([401, 422]);
    $this->assertGuest();
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with(['/accedi', '/api/v1/auth/login']);

it('revokes only the logged-out API session and rejects its token on reuse', function (): void {
    $user = User::factory()->create();
    $first = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->json('data.token');
    $second = $user->createToken('other-device')->plainTextToken;
    $this->withToken($first)->postJson('/api/v1/auth/logout')->assertOk();
    Auth::forgetGuards();
    $this->withToken($first)->getJson('/api/v1/me')->assertUnauthorized();
    Auth::forgetGuards();
    $this->withToken($second)->getJson('/api/v1/me')->assertOk();
});

it('saves a valid profile and rejects a partially invalid update atomically', function (): void {
    $user = User::factory()->create(['name' => 'Before']);
    $other = User::factory()->create(['name' => 'Other']);
    $this->actingAs($user)->patch('/il-mio-profilo', [
        'name' => 'Updated', 'timezone' => 'Europe/Rome', 'locale' => 'it',
        'quiet_from' => '23:00', 'quiet_to' => '07:00', 'reminders' => '1',
        'user_id' => $other->id, 'roles' => ['super_admin'], 'email' => 'stolen@example.test',
    ])->assertRedirect(route('account.profile'));
    expect($user->fresh()->name)->toBe('Updated')->and($user->fresh()->quiet_hours)->toBe(['from' => '23:00', 'to' => '07:00'])
        ->and($other->fresh()->name)->toBe('Other')->and($user->fresh()->email)->not->toBe('stolen@example.test')
        ->and($user->fresh()->hasRole('super_admin'))->toBeFalse();
    $this->patch('/il-mio-profilo', ['name' => 'Must not persist', 'timezone' => 'invalid-zone', 'locale' => 'it'])
        ->assertSessionHasErrors('timezone');
    expect($user->fresh()->name)->toBe('Updated')->and($user->fresh()->quiet_hours)->toBe(['from' => '23:00', 'to' => '07:00']);
});

it('rejects unauthenticated profile updates without modifying the requested user', function (): void {
    $user = User::factory()->create(['name' => 'Original']);
    $this->patch('/il-mio-profilo', ['user_id' => $user->id, 'name' => 'Tampered', 'timezone' => 'Europe/Rome', 'locale' => 'it'])
        ->assertRedirect(route('login'));
    expect($user->fresh()->name)->toBe('Original');
});

it('rejects a reset token belonging to another account without changing either password', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $token = Password::broker()->createToken($owner);
    $this->postJson('/api/v1/auth/password/reset', ['email' => $other->email, 'token' => $token, 'password' => 'replacement-password'])->assertUnprocessable();
    expect(Hash::check('password', $owner->fresh()->password))->toBeTrue()->and(Hash::check('password', $other->fresh()->password))->toBeTrue();
});

it('allows panel login only for an authorized member', function (string $panel): void {
    Filament::setCurrentPanel(Filament::getPanel($panel));
    $outsider = User::factory()->create();
    Livewire::test(Login::class)->fillForm(['email' => $outsider->email, 'password' => 'password'])->call('authenticate')->assertHasErrors(['data.email']);
    $this->assertGuest();
    $member = User::factory()->create();
    if ($panel === 'admin') {
        $member->assignRole('admin');
    } elseif ($panel === 'venue') {
        $venue = Venue::factory()->create(['status' => VenueStatus::Approved]);
        $venue->members()->attach($member, ['role' => 'owner']);
    } else {
        Organizer::query()->create(['name' => 'Organizzatore test', 'city_id' => City::query()->firstOrFail()->id, 'owner_id' => $member->id, 'is_active' => true]);
    }
    Livewire::test(Login::class)->fillForm(['email' => $member->email, 'password' => 'password'])->call('authenticate')->assertHasNoErrors();
    $this->assertAuthenticatedAs($member);
})->with(['admin', 'venue', 'organizer']);

it('limits website email requests even when every request targets a different address', function (string $path): void {
    config()->set('api.rate_limit.outbound_emails_per_hour', 2);
    foreach (range(1, 2) as $number) {
        $this->post($path, ['email' => 'target'.$number.'@example.test'])->assertRedirect();
    }
    $this->post($path, ['email' => 'target3@example.test'])->assertStatus(429);
    Notification::assertNothingSent();
})->with(['/accedi/collegamento', '/password-dimenticata']);

it('invalidates mobile challenges when the password changes before exchange', function (): void {
    $user = User::factory()->create();
    $verifier = str_repeat('v', 43);
    $raw = str_repeat('t', 64);
    MobileAuthChallenge::query()->create([
        'user_id' => $user->id, 'token_hash' => hash('sha256', $raw),
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'password_fingerprint' => MagicLoginLink::fingerprint($user), 'expires_at' => now()->addMinutes(15),
    ]);
    $user->update(['password' => 'new-password-value']);
    $this->postJson('/api/v1/auth/magic-link/exchange', ['token' => $raw, 'code_verifier' => $verifier])->assertBadRequest();
    expect($user->tokens()->count())->toBe(0);
});

it('resets a password through a real panel form and invalidates the old mobile session', function (string $panel): void {
    Filament::setCurrentPanel(Filament::getPanel($panel));
    $member = User::factory()->create();
    if ($panel === 'admin') {
        $member->assignRole('admin');
    } elseif ($panel === 'venue') {
        $venue = Venue::factory()->create(['status' => VenueStatus::Approved]);
        $venue->members()->attach($member, ['role' => 'owner']);
    } else {
        Organizer::query()->create(['name' => 'Organizzatore reset', 'city_id' => City::query()->firstOrFail()->id, 'owner_id' => $member->id, 'is_active' => true]);
    }
    $oldSession = $member->createToken('old-phone')->plainTextToken;
    $token = Password::broker()->createToken($member);
    Livewire::test(ResetPassword::class, ['email' => $member->email, 'token' => $token])
        ->fillForm(['password' => 'New-password-123456', 'passwordConfirmation' => 'New-password-123456'])
        ->call('resetPassword')->assertHasNoErrors();
    expect(Hash::check('New-password-123456', $member->fresh()->password))->toBeTrue()->and($member->tokens()->count())->toBe(0);
    Auth::forgetGuards();
    $this->withToken($oldSession)->getJson('/api/v1/me')->assertUnauthorized();
})->with(['admin', 'venue', 'organizer']);
