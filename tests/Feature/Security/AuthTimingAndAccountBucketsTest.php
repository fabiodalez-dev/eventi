<?php

declare(strict_types=1);

use App\Filament\Auth\Login;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Due modi di sapere o provare più di quanto il login lasci intendere: il
 * tempo di risposta, e le varianti accentate di un indirizzo.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
});

it('confronta la password di un indirizzo inesistente con un hash del costo configurato', function (): void {
    $costo = (int) config('hashing.bcrypt.rounds');

    /* Il controllo deve costare quanto quello di un account vero: un hash
       scritto a mano con un altro costo risponde in un tempo diverso. */
    $hasher = Mockery::mock(app('hash'));
    $hasher->shouldReceive('check')->once()
        ->withArgs(static fn (string $password, string $hash): bool => password_get_info($hash)['options']['cost'] === $costo)
        ->andReturnFalse();
    Hash::swap($hasher);

    $this->postJson('/api/v1/auth/login', ['email' => 'nessuno@example.test', 'password' => 'qualsiasi-cosa'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
});

it('fa aspettare il login fallito di sito e pannelli più di un bcrypt vero', function (): void {
    expect(config()->integer('auth.timebox_duration'))->toBeGreaterThanOrEqual(400000);
});

it('conta i tentativi sul pannello per account anche con le varianti accentate dell email', function (): void {
    $gestore = User::factory()->create(['email' => 'admin@example.test', 'password' => 'password-giusta-1']);
    $gestore->assignRole('admin');
    config()->set('api.rate_limit.attempts_per_account', 2);
    $this->get('/admin/login')->assertOk();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    foreach (['192.0.2.11', '192.0.2.12'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        Livewire::test(Login::class)->fillForm(['email' => 'admin@example.test', 'password' => 'sbagliata'])
            ->call('authenticate')->assertHasErrors(['data.email']);
    }

    /* 'àdmin@…' per la collation è lo stesso utente: niente secchio nuovo,
       nemmeno con la password giusta. */
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.13']);
    Livewire::test(Login::class)->fillForm(['email' => 'àdmin@example.test', 'password' => 'password-giusta-1'])
        ->call('authenticate')->assertHasErrors(['data.email']);

    expect(Auth::check())->toBeFalse();
});

it('conta le richieste del collegamento mobile sulla casella anche con le varianti accentate', function (): void {
    Notification::fake();
    $vittima = User::factory()->create(['email' => 'vittima@example.test']);
    config()->set('api.rate_limit.attempts_per_account', 2);
    $sfida = str_repeat('a', 43);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->postJson('/api/v1/auth/magic-link', ['email' => 'vittima@example.test', 'code_challenge' => $sfida])->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])->postJson('/api/v1/auth/magic-link', ['email' => 'vittima@example.test', 'code_challenge' => $sfida])->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.3'])->postJson('/api/v1/auth/magic-link', ['email' => 'vìttima@example.test', 'code_challenge' => $sfida])->assertTooManyRequests();

    Notification::assertSentToTimes($vittima, MagicLoginLink::class, 2);
});

it('rifiuta le varianti accentate nel collegamento mobile senza spedire niente', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'vittima@example.test']);

    $this->postJson('/api/v1/auth/magic-link', ['email' => 'vìttima@example.test', 'code_challenge' => str_repeat('a', 43)])
        ->assertUnprocessable();

    Notification::assertNothingSent();
});

it('lascia il secchio sulla stringa quando l account non esiste', function (): void {
    Notification::fake();
    config()->set('api.rate_limit.attempts_per_account', 1);

    /* Due indirizzi inesistenti diversi non si chiudono a vicenda. */
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21'])->postJson('/api/v1/auth/magic-link', ['email' => 'uno@example.test', 'code_challenge' => str_repeat('a', 43)])->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.22'])->postJson('/api/v1/auth/magic-link', ['email' => 'due@example.test', 'code_challenge' => str_repeat('a', 43)])->assertOk();
});
