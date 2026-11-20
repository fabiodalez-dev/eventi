<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\VerifyEmailLink;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * La conferma dell'indirizzo dopo l'iscrizione (§15.2): il collegamento si
 * apre una volta o due senza danni, smette di valere quando l'indirizzo non è
 * più quello, si rispedisce con un limite, e finché manca tiene chiuse le
 * stesse porte sul sito e nell'app.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    Notification::fake();
    config(['community.enabled' => false]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** Il collegamento dell'ultima email di conferma spedita. */
function iscrizioneLinkConferma(User $user): string
{
    $notifica = Notification::sent($user, VerifyEmailLink::class)->last();

    expect($notifica)->toBeInstanceOf(VerifyEmailLink::class);

    return (string) $notifica->toMail($user)->actionUrl;
}

/** Un account vecchio, con password scelta e indirizzo mai confermato. */
function iscrizioneNonConfermato(string $email, string $password = 'password-non-confermata'): User
{
    return User::factory()->unverified()->create(['email' => $email, 'password' => Hash::make($password)]);
}

it('apre due volte lo stesso collegamento di conferma senza errori e senza riscrivere la data della conferma', function (): void {
    $user = iscrizioneNonConfermato('doppio.click@example.test');

    $this->actingAs($user)->post('/email/verifica')->assertRedirect();
    $link = iscrizioneLinkConferma($user);
    $this->post('/esci');
    $this->assertGuest();

    /* Dal telefono, senza sessione: conferma e manda all'accesso. */
    $this->get($link)->assertRedirect(route('login'))->assertSessionHas('status', __('account.verify.done'));
    $confermata = $user->fresh()->email_verified_at;
    expect($confermata)->not->toBeNull();

    /* Un quarto d'ora dopo lo riapre dal computer: stessa risposta, stessa data. */
    CarbonImmutable::setTestNow(now()->addMinutes(15));
    $this->get($link)->assertRedirect(route('login'))->assertSessionHas('status', __('account.verify.done'));

    expect($user->fresh()->email_verified_at?->equalTo($confermata))->toBeTrue();
    $this->assertGuest();

    $this->post('/accedi', ['email' => 'doppio.click@example.test', 'password' => 'password-non-confermata'])
        ->assertRedirect(route('account.feed'));
    $this->get('/i-miei-salvataggi')->assertOk();
});

it('non conferma più un indirizzo che nel frattempo è cambiato', function (): void {
    $user = iscrizioneNonConfermato('vecchio.indirizzo@example.test');

    $this->actingAs($user)->post('/email/verifica')->assertRedirect();
    $link = iscrizioneLinkConferma($user);

    /* La redazione corregge l'indirizzo prima che la persona apra il messaggio. */
    $user->forceFill(['email' => 'nuovo.indirizzo@example.test'])->save();
    $this->post('/esci');

    $this->get($link)->assertRedirect(route('login'))->assertSessionHas('status', __('account.verify.failed'));
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    /* Il nuovo indirizzo si conferma con un collegamento nuovo. */
    $this->post('/accedi', ['email' => 'nuovo.indirizzo@example.test', 'password' => 'password-non-confermata'])->assertRedirect();
    $this->post('/email/verifica')->assertRedirect();
    $this->get(iscrizioneLinkConferma($user->fresh()))->assertRedirect(route('account.profile'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('mostra la pagina del collegamento scaduto e lascia chiederne un altro che funziona', function (): void {
    $user = iscrizioneNonConfermato('in.ritardo@example.test');

    $this->actingAs($user)->post('/email/verifica');
    $vecchio = iscrizioneLinkConferma($user);

    $this->travel(config()->integer('account.verification_link_minutes') + 1)->minutes();

    $this->get($vecchio)->assertForbidden()->assertViewIs('errors.link-expired');
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    /* Dalla pagina di avviso ne chiede uno nuovo. */
    $this->get('/email/verifica')->assertOk()->assertSee('in.ritardo@example.test');
    $this->from('/email/verifica')->post('/email/verifica')->assertRedirect('/email/verifica');
    Notification::assertSentToTimes($user, VerifyEmailLink::class, 2);

    $this->get(iscrizioneLinkConferma($user))->assertRedirect(route('account.profile'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('limita i reinvii della conferma e li riapre passato il minuto', function (): void {
    $user = iscrizioneNonConfermato('reinvio.a.raffica@example.test');
    $this->actingAs($user);

    foreach (range(1, config()->integer('api.rate_limit.auth')) as $volta) {
        $this->from('/email/verifica')->post('/email/verifica')
            ->assertRedirect('/email/verifica')
            ->assertSessionHas('status', __('account.verify.sent'));
    }

    $this->from('/email/verifica')->post('/email/verifica')->assertStatus(429);
    Notification::assertSentToTimes($user, VerifyEmailLink::class, config()->integer('api.rate_limit.auth'));

    $this->travel(61)->seconds();
    $this->from('/email/verifica')->post('/email/verifica')->assertRedirect('/email/verifica');
    Notification::assertSentToTimes($user, VerifyEmailLink::class, config()->integer('api.rate_limit.auth') + 1);
});

it('conferma dal telefono mentre il computer resta collegato, che da lì in poi trova le porte aperte', function (): void {
    $user = iscrizioneNonConfermato('due.dispositivi@example.test');

    $this->post('/accedi', ['email' => 'due.dispositivi@example.test', 'password' => 'password-non-confermata'])
        ->assertRedirect(route('account.feed'));
    $this->get('/le-mie-raccolte')->assertRedirect(route('verification.notice'));
    $this->post('/email/verifica')->assertRedirect();
    $link = iscrizioneLinkConferma($user);

    /* Il telefono apre il messaggio: nessuna sessione lì. */
    $telefono = $this->app->make('auth')->guard('web');
    $telefono->logout();
    $this->flushSession();
    $this->get($link)->assertRedirect(route('login'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    /* Il computer, con la sua sessione, ora entra dove prima era fermato. */
    $this->actingAs($user->fresh());
    $this->get('/email/verifica')->assertRedirect(route('account.profile'));
    $this->get('/le-mie-raccolte')->assertOk();
    $this->get('/i-miei-salvataggi')->assertOk();
});

it('dall app tiene chiusi i salvataggi a chi non ha confermato, poi li apre dopo la verifica rimandata dall app', function (): void {
    $user = iscrizioneNonConfermato('app.non.confermata@example.test', 'password-dell-app-lunga');

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'app.non.confermata@example.test',
        'password' => 'password-dell-app-lunga',
        'device_name' => 'Telefono di prova',
    ])->assertOk()->json('data.token');

    $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'app.non.confermata@example.test');
    $this->withToken($token)->getJson('/api/v1/me/saved')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');

    $this->withToken($token)->postJson('/api/v1/auth/verification/resend')->assertOk()->assertJsonPath('data.message', __('account.verify.sent'));
    Notification::assertSentToTimes($user, VerifyEmailLink::class, 1);

    /* Un collegamento inventato non conferma niente. */
    $this->withToken($token)->postJson('/api/v1/auth/verify-email', ['url' => url('/email/verifica/'.$user->id.'/'.sha1('app.non.confermata@example.test'))])
        ->assertStatus(400);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    $this->withToken($token)->postJson('/api/v1/auth/verify-email', ['url' => iscrizioneLinkConferma($user)])
        ->assertOk()->assertJsonPath('data.message', __('account.api.email_verified'));

    /* La richiesta dopo è un'altra richiesta: nessuna guardia ricorda l'utente
       letto prima della conferma (nella suite il container sopravvive). */
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me/saved')->assertOk();

    /* Confermato, il reinvio risponde uguale ma non spedisce più. */
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/v1/auth/verification/resend')->assertOk();
    Notification::assertSentToTimes($user, VerifyEmailLink::class, 1);
});

it('non conferma l indirizzo di un account cancellato anche con un collegamento ancora valido', function (): void {
    $user = iscrizioneNonConfermato('cancellato.prima@example.test');

    $this->actingAs($user)->post('/email/verifica');
    $link = iscrizioneLinkConferma($user);

    $this->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword'), 'password' => 'password-non-confermata'])
        ->assertRedirect(route('home'));
    $this->assertGuest();

    $this->get($link)->assertRedirect(route('login'))->assertSessionHas('status', __('account.verify.failed'));

    $cancellato = User::withTrashed()->findOrFail($user->id);
    expect($cancellato->trashed())->toBeTrue()
        ->and($cancellato->email_verified_at)->toBeNull();
});
