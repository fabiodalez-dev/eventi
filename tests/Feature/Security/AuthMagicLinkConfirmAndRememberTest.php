<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\MagicLoginLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Il collegamento di accesso del sito: entra con un clic solo nel browser che
 * l'ha chiesto, e non accende da solo il cookie «resta collegato».
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    Notification::fake();
});

function collegamentoSpedito(User $user): string
{
    $url = '';
    Notification::assertSentTo($user, MagicLoginLink::class, function (MagicLoginLink $notifica) use ($user, &$url): bool {
        $url = (string) $notifica->toMail($user)->actionUrl;

        return true;
    });

    return $url;
}

function nomeRicordami(): string
{
    return Auth::guard('web')->getRecallerName();
}

it('aperto in un altro browser mostra la conferma senza entrare né consumare il collegamento', function (): void {
    $user = User::factory()->create(['email' => 'mario.rossi@example.test']);
    $url = MagicLoginLink::url($user);

    /* Lo scanner di posta apre il collegamento due volte: nessuna delle due
       entra o lo brucia. */
    $this->get($url)->assertOk()->assertSee('m**********@example.test')->assertDontSee('mario.rossi@');
    $this->get($url)->assertOk();

    $this->assertGuest();
    expect(DB::table('used_web_login_links')->count())->toBe(0);

    /* Il pulsante entra, una volta sola. */
    $this->post($url)->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);

    auth()->logout();
    session()->flush();
    $this->post($url)->assertRedirect(route('account.magic-link'));
    $this->get($url)->assertRedirect(route('account.magic-link'));
    $this->assertGuest();
});

it('nel browser che l ha chiesto entra con un clic', function (): void {
    $user = User::factory()->create();

    $this->post(route('account.magic-link.store'), ['email' => $user->email])->assertRedirect();

    $this->get(collegamentoSpedito($user))->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);
});

it('l impronta di un altro browser non basta: chiede la conferma', function (): void {
    $user = User::factory()->create();

    $this->post(route('account.magic-link.store'), ['email' => $user->email])->assertRedirect();
    $url = collegamentoSpedito($user);

    /* Un browser pulito: è il caso del collegamento girato a un altro. */
    session()->flush();
    $this->flushSession();

    $this->get($url)->assertOk();
    $this->assertGuest();
});

it('rifiuta il POST con la firma manomessa', function (): void {
    $user = User::factory()->create();

    $this->post(MagicLoginLink::url($user).'0')->assertForbidden();
    $this->assertGuest();
});

it('non accende il cookie resta collegato se non è chiesto', function (): void {
    $user = User::factory()->create();

    $this->post(route('account.magic-link.store'), ['email' => $user->email]);
    $this->get(collegamentoSpedito($user))->assertRedirect()->assertCookieMissing(nomeRicordami());
});

it('accende il cookie resta collegato quando è chiesto, per trenta giorni', function (): void {
    $user = User::factory()->create();

    $this->post(route('account.magic-link.store'), ['email' => $user->email, 'remember' => '1']);
    $risposta = $this->get(collegamentoSpedito($user))->assertRedirect()->assertCookie(nomeRicordami());

    expect($risposta->getCookie(nomeRicordami(), false)?->getExpiresTime())
        ->toBeLessThanOrEqual(now()->addDays(30)->timestamp + 5);
});

it('dalla pagina di conferma vale la casella spuntata lì', function (): void {
    $user = User::factory()->create();
    $url = MagicLoginLink::url($user);

    $this->post($url)->assertRedirect()->assertCookieMissing(nomeRicordami());

    auth()->logout();
    session()->flush();
    $altro = User::factory()->create();
    $this->post(MagicLoginLink::url($altro), ['remember' => '1'])->assertRedirect()->assertCookie(nomeRicordami());
});

it('la registrazione dal sito non accende il cookie resta collegato da sola', function (): void {
    $this->post(route('account.register.store'), [
        'name' => 'Luca',
        'email' => 'luca@example.test',
        'password' => 'una-password-molto-lunga',
        'password_confirmation' => 'una-password-molto-lunga',
    ])->assertRedirect(route('verification.notice'))->assertCookieMissing(nomeRicordami());

    $this->assertAuthenticated();
});

it('dura trenta giorni anche il resta collegato dell accesso con password', function (): void {
    $user = User::factory()->create(['password' => 'password-lunga-1']);

    $risposta = $this->post(route('account.login.store'), ['email' => $user->email, 'password' => 'password-lunga-1', 'remember' => '1'])
        ->assertRedirect()->assertCookie(nomeRicordami());

    /* Non i 400 giorni predefiniti di Laravel. */
    expect($risposta->getCookie(nomeRicordami(), false)?->getExpiresTime())
        ->toBeLessThanOrEqual(now()->addDays(30)->timestamp + 5)
        ->toBeGreaterThan(now()->addDays(29)->timestamp);
});
