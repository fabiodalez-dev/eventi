<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;

/*
 * Reimpostare la password dal sito (§15.2).
 *
 * `ResetPasswordLink` costruiva da sempre un collegamento verso
 * `/reimposta-password` quando `API_PASSWORD_RESET_URL` non e' impostato — che
 * e' il caso in produzione. Quella pagina pero' non esisteva: chi chiedeva una
 * password nuova riceveva un'email con dentro un 404.
 */

it('manda il collegamento a chi esiste', function (): void {
    Notification::fake();

    $utente = User::factory()->create(['email' => 'chi.esiste@example.test']);

    $this->post(route('account.password.email'), ['email' => 'chi.esiste@example.test'])
        ->assertRedirect(route('account.password.request'))
        ->assertSessionHas('status');

    Notification::assertSentTo($utente, ResetPasswordLink::class);
});

it('risponde allo stesso modo a un indirizzo che non esiste', function (): void {
    /*
     * Il cuore della faccenda: una risposta diversa direbbe a chiunque quali
     * email sono registrate, una prova alla volta. E' il modo piu' economico
     * per costruire una lista di bersagli.
     */
    Notification::fake();

    $conAccount = $this->post(route('account.password.email'), ['email' => 'nessuno@example.test']);

    $utente = User::factory()->create(['email' => 'esiste@example.test']);
    $senzaAccount = $this->post(route('account.password.email'), ['email' => 'esiste@example.test']);

    expect($conAccount->getStatusCode())->toBe($senzaAccount->getStatusCode())
        ->and(session('status'))->not->toBeNull();

    $conAccount->assertRedirect(route('account.password.request'));
    $senzaAccount->assertRedirect(route('account.password.request'));

    Notification::assertSentTo($utente, ResetPasswordLink::class);
    Notification::assertCount(1);
});

it('il collegamento della mail porta a una pagina che esiste', function (): void {
    /*
     * La ragione per cui questo lavoro e' stato fatto: l'indirizzo nella
     * notifica e questa rotta devono coincidere. Se qualcuno rinomina la
     * seconda, questo test cade prima che cada l'email di qualcun altro.
     */
    $utente = User::factory()->create(['email' => 'destinatario@example.test']);
    $token = Password::broker()->createToken($utente);

    $notifica = new ResetPasswordLink($token);
    $messaggio = $notifica->toMail($utente);

    $collegamento = $messaggio->actionUrl;

    expect($collegamento)->toContain('/reimposta-password')
        ->and($collegamento)->toContain($token);

    $this->get($collegamento)->assertOk()->assertSee($utente->email);
});

it('cambia la password e butta fuori i dispositivi', function (): void {
    Event::fake([PasswordReset::class]);

    $utente = User::factory()->create([
        'email' => 'cambio@example.test',
        'password' => Hash::make('quella-vecchia'),
    ]);
    $utente->createToken('telefono');

    $token = Password::broker()->createToken($utente);

    $this->post(route('account.password.update'), [
        'token' => $token,
        'email' => 'cambio@example.test',
        'password' => 'una-password-lunga-e-nuova',
        'password_confirmation' => 'una-password-lunga-e-nuova',
    ])->assertRedirect(route('login'))->assertSessionHas('status');

    $utente->refresh();

    expect(Hash::check('una-password-lunga-e-nuova', $utente->password))->toBeTrue()
        /* Chi reimposta perche' teme un accesso altrui non ottiene niente se i
           token vecchi restano vivi. */
        ->and($utente->tokens()->count())->toBe(0);

    Event::assertDispatched(PasswordReset::class);
});

it('rifiuta un token che non vale, senza dire quale dei motivi', function (): void {
    $utente = User::factory()->create([
        'email' => 'intatta@example.test',
        'password' => Hash::make('resta-questa'),
    ]);

    $this->from(route('account.password.reset'))
        ->post(route('account.password.update'), [
            'token' => 'un-token-inventato',
            'email' => 'intatta@example.test',
            'password' => 'password-nuova-abbastanza-lunga',
            'password_confirmation' => 'password-nuova-abbastanza-lunga',
        ])
        ->assertRedirect(route('account.password.reset'))
        ->assertSessionHasErrors('email');

    expect(Hash::check('resta-questa', $utente->refresh()->password))->toBeTrue();
});

it('pretende la conferma della password', function (): void {
    $utente = User::factory()->create(['email' => 'conferma@example.test']);
    $token = Password::broker()->createToken($utente);

    $this->from(route('account.password.reset'))
        ->post(route('account.password.update'), [
            'token' => $token,
            'email' => 'conferma@example.test',
            'password' => 'password-lunga-e-buona',
            'password_confirmation' => 'ma-diversa-da-quella-sopra',
        ])
        ->assertSessionHasErrors('password');
});

it('offre il recupero dalla pagina di accesso', function (): void {
    /* Chi si e' arenato deve trovare la via d'uscita dove si e' arenato. */
    $this->get(route('login'))
        ->assertOk()
        ->assertSee(route('account.password.request'), false)
        ->assertSee(__('account.forgot.from_login'), false);
});

it('lo offre anche al pannello di amministrazione', function (): void {
    /*
     * Un amministratore che dimentica la password restava fuori: non c'e'
     * registrazione da rifare e nessuno a cui chiedere, perche' chi potrebbe
     * rimediare e' lui. Il pannello dei locali lo aveva gia'.
     */
    expect(Route::has('filament.admin.auth.password-reset.request'))->toBeTrue();
});
