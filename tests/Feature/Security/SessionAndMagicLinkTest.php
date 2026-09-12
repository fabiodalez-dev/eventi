<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\MagicLoginLink;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

/**
 * Due modi di restare dentro un account che non era più tuo.
 */
beforeEach(function (): void {
    testCity();
});

/**
 * Reimpostare la password chiude le sessioni aperte.
 *
 * Prima non le chiudeva: chi si era fatto rubare la password la cambiava e il
 * ladro restava dentro col suo cookie fino alla scadenza naturale della
 * sessione. Cioè il gesto con cui una persona riprende il controllo del
 * proprio account non riprendeva il controllo di niente.
 */
it('butta fuori la sessione aperta con la password vecchia', function (): void {
    $utente = User::factory()->create(['password' => Hash::make('vecchia-password-1')]);

    /* Il ladro: entra con la password rubata e ottiene una sessione. */
    $risposta = $this->post(route('account.login.store'), [
        'email' => $utente->email,
        'password' => 'vecchia-password-1',
    ]);

    $risposta->assertRedirect();
    $this->get(route('account.profile'))->assertOk();

    /* La vittima reimposta la password da un altro dispositivo. */
    $utente->forceFill(['password' => Hash::make('nuova-password-1')])->save();

    /*
     * `forgetGuards()` non indebolisce l'asserzione: la rimette in piedi.
     *
     * Nei test l'applicazione è avviata una volta e il guard è un singleton,
     * quindi fra due `$this->get()` dello stesso test l'utente resta in cache
     * con l'impronta VECCHIA — e il middleware, che confronta quella, non
     * trova nessuna differenza. In produzione ogni richiesta è un processo
     * nuovo e l'utente si rilegge dal database: questa riga riproduce quella
     * condizione, invece di provare qualcosa che nella realtà non capita.
     */
    $this->app['auth']->forgetGuards();

    /* La sessione del ladro non vale più. */
    $this->get(route('account.profile'))->assertRedirect(route('login'));
});

it('non tocca la sessione di chi non ha cambiato password', function (): void {
    $utente = User::factory()->create(['password' => Hash::make('password-stabile-1')]);

    $this->post(route('account.login.store'), [
        'email' => $utente->email,
        'password' => 'password-stabile-1',
    ]);

    /* Tre richieste di fila, ognuna con il guard ripulito come in produzione:
       l'impronta si scrive una volta e combacia sempre. Se il middleware fosse
       troppo zelante, qui si vedrebbe un logout. */
    $this->get(route('account.profile'))->assertOk();
    $this->app['auth']->forgetGuards();
    $this->get(route('account.saved'))->assertOk();
    $this->get(route('account.profile'))->assertOk();
});

/**
 * Il collegamento di accesso vale una volta sola.
 *
 * Era un indirizzo firmato a scadenza e nient'altro: per tutti i suoi quindici
 * minuti funzionava ogni volta che lo si apriva. Un'email inoltrata per
 * sbaglio, una cronologia condivisa, un proxy aziendale che registra le URL —
 * e chiunque avesse quella riga entrava. Il canale mobile lo bruciava già al
 * primo scambio; questo no.
 */
it('brucia il collegamento di accesso dopo il primo uso', function (): void {
    $utente = User::factory()->create();

    $collegamento = URL::temporarySignedRoute(
        'account.magic-link.login',
        now()->addMinutes(config()->integer('account.magic_link_minutes')),
        ['user' => $utente->getKey(), 'fingerprint' => MagicLoginLink::fingerprint($utente)],
    );

    /* Il primo uso entra. */
    $this->get($collegamento)->assertRedirect(route('account.feed'));
    expect(auth()->id())->toBe($utente->getKey());

    /* Il secondo no, nemmeno da un browser pulito e dentro la scadenza. */
    auth()->logout();
    session()->flush();

    $this->get($collegamento)->assertRedirect(route('account.magic-link'));
    expect(auth()->check())->toBeFalse();
});
