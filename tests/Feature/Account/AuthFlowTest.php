<?php

declare(strict_types=1);

use App\Enums\NotificationStatus;
use App\Models\Device;
use App\Models\Follow;
use App\Models\SavedEvent;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Notifications\VerifyEmailLink;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/**
 * Registrazione, accesso, collegamento senza password, verifica dell'indirizzo
 * e cancellazione dell'account (§15.2 e §15.9).
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
});

it('registra dal sito con il nome facoltativo e manda subito la verifica', function (): void {
    $this->post('/registrati', [
        'email' => 'giulia@example.test',
        'password' => 'una-password-molto-lunga',
        'password_confirmation' => 'una-password-molto-lunga',
    ])->assertRedirect(route('account.feed'));

    $user = User::query()->where('email', 'giulia@example.test')->firstOrFail();

    expect($user->name)->toBeNull()
        ->and($user->hasRole('user'))->toBeTrue()
        ->and($user->timezone)->toBe('Europe/Rome')
        ->and($user->locale)->toBe('it')
        /* Il consenso marketing è un'altra cosa e nasce spento (§15.9). */
        ->and($user->marketing_opt_in_at)->toBeNull()
        ->and(Auth::check())->toBeTrue();

    /* §15.2: la verifica parte subito, perché è la condizione di ogni invio
       successivo. */
    Notification::assertSentTo($user, VerifyEmailLink::class);
});

it('registra il consenso alla newsletter con una data propria', function (): void {
    $this->post('/registrati', [
        'email' => 'marco@example.test',
        'password' => 'una-password-molto-lunga',
        'password_confirmation' => 'una-password-molto-lunga',
        'marketing_opt_in' => '1',
    ])->assertRedirect();

    expect(User::query()->where('email', 'marco@example.test')->firstOrFail()->marketing_opt_in_at)->not->toBeNull();
});

it('rifiuta la registrazione di un robot che compila il campo esca', function (): void {
    $this->post('/registrati', [
        'email' => 'robot@example.test',
        'password' => 'una-password-molto-lunga',
        'password_confirmation' => 'una-password-molto-lunga',
        'website_url' => 'https://spam.example',
    ])->assertSessionHasErrors('website_url');

    expect(User::query()->where('email', 'robot@example.test')->exists())->toBeFalse();
});

it('fa accedere con la password e risponde allo stesso modo a email e password sbagliate', function (): void {
    $user = User::factory()->create(['email' => 'entra@example.test']);

    $this->post('/accedi', ['email' => 'entra@example.test', 'password' => 'password'])
        ->assertRedirect(route('account.feed'));

    expect(Auth::id())->toBe($user->getKey());

    Auth::logout();

    /*
     * Lo stesso messaggio per «password sbagliata» e «email inesistente»: due
     * messaggi diversi direbbero a chiunque quali indirizzi sono registrati.
     */
    $this->post('/accedi', ['email' => 'entra@example.test', 'password' => 'non-e-questa'])
        ->assertSessionHasErrors(['email' => __('account.login.failed')]);

    $this->post('/accedi', ['email' => 'mai-vista@example.test', 'password' => 'non-e-questa'])
        ->assertSessionHasErrors(['email' => __('account.login.failed')]);

    expect(Auth::check())->toBeFalse();
});

it('manda il collegamento di accesso e lo fa valere una volta sola per quella password', function (): void {
    $user = User::factory()->create(['email' => 'senza-password@example.test']);

    $this->post('/accedi/collegamento', ['email' => 'senza-password@example.test'])
        ->assertRedirect(route('account.magic-link'));

    Notification::assertSentTo($user, MagicLoginLink::class);

    $url = MagicLoginLink::url($user);

    $this->get($url)->assertRedirect(route('account.feed'));
    expect(Auth::id())->toBe($user->getKey());

    /* Cambiare la password invalida i collegamenti già spediti: è ciò che
       serve a chi la cambia proprio perché teme un accesso altrui. */
    Auth::logout();
    $user->forceFill(['password' => bcrypt('un-altra-password')])->save();

    $this->get($url)->assertRedirect(route('account.magic-link'));
    expect(Auth::check())->toBeFalse();
});

it('risponde allo stesso modo a un indirizzo che non esiste', function (): void {
    $this->post('/accedi/collegamento', ['email' => 'mai-vista@example.test'])
        ->assertRedirect(route('account.magic-link'))
        ->assertSessionHas('status', __('account.magic.sent'));

    Notification::assertNothingSent();
});

it('verifica l indirizzo dal collegamento firmato, anche senza sessione', function (): void {
    Event::fake([Verified::class]);

    $user = User::factory()->unverified()->create();

    $this->get(VerifyEmailLink::url($user))->assertRedirect(route('login'));

    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue()
        ->and($user->fresh()?->canReceiveNotifications())->toBeTrue();

    Event::assertDispatched(Verified::class);
});

it('rifiuta un collegamento di verifica manomesso', function (): void {
    $user = User::factory()->unverified()->create();

    $manomesso = str_replace('signature=', 'signature=x', VerifyEmailLink::url($user));

    $this->get($manomesso)->assertForbidden();

    expect($user->fresh()?->hasVerifiedEmail())->toBeFalse();
});

it('verifica l indirizzo dall API rimandando indietro il collegamento intero', function (): void {
    $user = User::factory()->unverified()->create();

    $this->postJson('/api/v1/auth/verify-email', ['url' => VerifyEmailLink::url($user)])
        ->assertOk()
        ->assertJsonPath('data.message', __('account.api.email_verified'));

    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();

    /* Un indirizzo senza firma non verifica niente: il `hash` da solo è
       `sha1(email)`, calcolabile da chiunque. */
    $altro = User::factory()->unverified()->create();

    $this->postJson('/api/v1/auth/verify-email', [
        'url' => url('/email/verifica/'.$altro->getKey().'/'.sha1((string) $altro->email)),
    ])->assertStatus(400)->assertJsonPath('error.code', 'INVALID_TOKEN');

    expect($altro->fresh()?->hasVerifiedEmail())->toBeFalse();
});

it('cancella l account anonimizzandolo e spegnendo subito salvataggi e promemoria', function (): void {
    $city = testCity();
    $category = testCategory();
    freezeLocal($city, '2026-09-10 18:00');

    $occurrence = occurrenceAt($city, $category, '2026-09-12 19:00:00');

    $user = User::factory()->create(['email' => 'addio@example.test', 'name' => 'Chi se ne va']);

    SavedEvent::query()->create(['user_id' => $user->getKey(), 'occurrence_id' => $occurrence->getKey()]);
    Follow::factory()->create(['user_id' => $user->getKey()]);
    Device::factory()->create(['user_id' => $user->getKey()]);

    $pending = ScheduledNotification::factory()->create([
        'user_id' => $user->getKey(),
        'status' => NotificationStatus::Pending,
        'dedupe_key' => 'reminder_3h:user_'.$user->getKey().':occ_'.$occurrence->getKey(),
    ]);

    $token = $user->createToken('Telefono')->plainTextToken;

    $this->actingAs($user)
        ->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword')])
        ->assertRedirect(route('home'));

    $user->refresh();

    expect($user->trashed())->toBeTrue()
        ->and($user->name)->toBeNull()
        ->and($user->email)->toBe('utente-'.$user->getKey().'@anonimo.invalid')
        ->and($user->marketing_opt_in_at)->toBeNull()
        ->and($user->savedEvents()->count())->toBe(0)
        ->and($user->follows()->count())->toBe(0)
        ->and($user->devices()->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0)
        ->and($pending->fresh()?->status)->toBe(NotificationStatus::Cancelled);

    /* Il token muore con l'account: la richiesta successiva dell'app è un 401,
       che è la conferma più onesta che si possa dare. */
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();

    Carbon::setTestNow();
});

it('pretende la parola di conferma prima di cancellare', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/il-mio-profilo', ['conferma' => 'forse'])
        ->assertSessionHasErrors('conferma');

    expect($user->fresh()?->trashed())->toBeFalse();
});
