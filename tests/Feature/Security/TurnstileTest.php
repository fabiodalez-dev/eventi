<?php

declare(strict_types=1);

use App\Models\EventSubmission;
use App\Models\Report;
use App\Models\User;
use App\Models\VenueApplication;
use App\Support\Honeypot;
use App\Support\Turnstile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Turnstile sui moduli pubblici (§14.7).
 *
 * Il comportamento che conta è doppio, e va verificato in entrambi i versi:
 * con le chiavi la verifica è obbligatoria, **senza le chiavi il modulo deve
 * funzionare come prima**. La seconda metà non è un caso limite: è la
 * configurazione di sviluppo, dei test e dell'integrazione continua.
 */
beforeEach(function (): void {
    RateLimiter::clear('public-forms');
});

/**
 * Accende Turnstile per la durata di un test. Le chiavi sono finte: la
 * chiamata a Cloudflare è intercettata da `Http::fake()`.
 */
function enableTurnstile(): void
{
    config()->set('services.turnstile.site_key', 'sito-di-prova');
    config()->set('services.turnstile.secret_key', 'segreto-di-prova');
}

function turnstileAnswers(bool $success): void
{
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => $success]),
    ]);
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function validSubmission(array $extra = []): array
{
    return [
        'title' => 'Concerto in cortile',
        'contact_email' => 'mario@example.com',
        Honeypot::FIELD => '',
        ...$extra,
    ];
}

it('non chiede nulla e non disegna nulla quando le chiavi mancano', function (): void {
    testCity();

    config()->set('services.turnstile.site_key', '');
    config()->set('services.turnstile.secret_key', '');

    Http::preventStrayRequests();

    expect(Turnstile::enabled())->toBeFalse()
        ->and(Turnstile::rules())->toBe([]);

    $this->get('/proponi-evento')->assertOk()->assertDontSee('cf-turnstile', escape: false);

    $this->post('/proponi-evento', validSubmission())->assertSessionHasNoErrors();

    expect(EventSubmission::query()->count())->toBe(1);
});

it('resta spento quando è configurata una chiave sola', function (string $site, string $secret): void {
    config()->set('services.turnstile.site_key', $site);
    config()->set('services.turnstile.secret_key', $secret);

    expect(Turnstile::enabled())->toBeFalse();
})->with([
    'solo la pubblica' => ['sito-di-prova', ''],
    'solo la segreta' => ['', 'segreto-di-prova'],
]);

it('disegna il riquadro nei quattro moduli pubblici quando è acceso', function (string $url): void {
    testCity();
    enableTurnstile();

    $this->get($url)
        ->assertOk()
        ->assertSee('cf-turnstile', escape: false)
        ->assertSee('data-sitekey="sito-di-prova"', escape: false)
        ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js', escape: false);
})->with([
    'proponi un evento' => ['/proponi-evento'],
    'registra il tuo locale' => ['/registra-il-tuo-locale'],
    'registrazione' => ['/registrati'],
]);

it('disegna il riquadro anche nel modulo di segnalazione', function (): void {
    $city = testCity();
    $category = testCategory();
    enableTurnstile();

    $occurrence = occurrenceAt($city, $category, '2026-09-10 19:00:00');

    $this->get('/eventi/'.$occurrence->event->slug.'/segnala')
        ->assertOk()
        ->assertSee('cf-turnstile', escape: false);
});

it('rifiuta la proposta senza gettone', function (): void {
    testCity();
    enableTurnstile();

    Http::preventStrayRequests();

    $this->post('/proponi-evento', validSubmission())->assertSessionHasErrors(Turnstile::FIELD);

    expect(EventSubmission::query()->count())->toBe(0);
});

it('rifiuta la proposta quando Cloudflare dice di no', function (): void {
    testCity();
    enableTurnstile();
    turnstileAnswers(false);

    $this->post('/proponi-evento', validSubmission([Turnstile::FIELD => 'gettone-rifiutato']))
        ->assertSessionHasErrors([Turnstile::FIELD => __('validation.custom.turnstile.failed')]);

    expect(EventSubmission::query()->count())->toBe(0);
});

it('accetta la proposta quando Cloudflare dice di sì, passando segreto e indirizzo IP', function (): void {
    testCity();
    enableTurnstile();
    turnstileAnswers(true);

    $this->post('/proponi-evento', validSubmission([Turnstile::FIELD => 'gettone-buono']))
        ->assertSessionHasNoErrors();

    expect(EventSubmission::query()->count())->toBe(1);

    Http::assertSent(function ($request): bool {
        $data = $request->data();

        return $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $data['secret'] === 'segreto-di-prova'
            && $data['response'] === 'gettone-buono'
            && array_key_exists('remoteip', $data);
    });
});

it('protegge la richiesta di registrazione di un locale', function (): void {
    testCity();
    enableTurnstile();
    turnstileAnswers(false);

    $this->post('/registra-il-tuo-locale', [
        'venue_name' => 'Circolo di prova',
        'contact_name' => 'Mario Rossi',
        'contact_email' => 'mario@example.com',
        Honeypot::FIELD => '',
        Turnstile::FIELD => 'gettone-rifiutato',
    ])->assertSessionHasErrors(Turnstile::FIELD);

    expect(VenueApplication::query()->count())->toBe(0);
});

it('protegge la segnalazione', function (): void {
    $city = testCity();
    $category = testCategory();
    enableTurnstile();
    turnstileAnswers(false);

    $occurrence = occurrenceAt($city, $category, '2026-09-10 19:00:00');

    $this->post('/eventi/'.$occurrence->event->slug.'/segnala', [
        'reason' => 'wrong_info',
        Honeypot::FIELD => '',
        Turnstile::FIELD => 'gettone-rifiutato',
    ])->assertSessionHasErrors(Turnstile::FIELD);

    expect(Report::query()->count())->toBe(0);
});

it('protegge la registrazione di un account', function (): void {
    testCity();
    enableTurnstile();
    turnstileAnswers(false);

    $this->post('/registrati', [
        'email' => 'nuovo@example.com',
        'password' => 'password-lunga-abbastanza',
        'password_confirmation' => 'password-lunga-abbastanza',
        Honeypot::FIELD => '',
        Turnstile::FIELD => 'gettone-rifiutato',
    ])->assertSessionHasErrors(Turnstile::FIELD);

    expect(User::query()->where('email', 'nuovo@example.com')->exists())->toBeFalse();
});

it('non chiude il modulo quando Cloudflare è irraggiungibile', function (): void {
    // Un guasto del servizio esterno non deve diventare un guasto nostro: il
    // campo esca e il limite di frequenza restano in piedi e sono nostri.
    testCity();
    enableTurnstile();

    Http::fake(fn () => throw new ConnectionException('timeout'));

    $this->post('/proponi-evento', validSubmission([Turnstile::FIELD => 'gettone-non-verificabile']))
        ->assertSessionHasNoErrors();

    expect(EventSubmission::query()->count())->toBe(1);
});

it('non chiude il modulo quando Cloudflare risponde con un errore del proprio server', function (): void {
    testCity();
    enableTurnstile();

    Http::fake(['challenges.cloudflare.com/*' => Http::response('', 503)]);

    $this->post('/proponi-evento', validSubmission([Turnstile::FIELD => 'gettone-non-verificabile']))
        ->assertSessionHasNoErrors();

    expect(EventSubmission::query()->count())->toBe(1);
});

it('ferma il campo esca anche quando il gettone è valido', function (): void {
    // Le tre barriere di §14.7 sono indipendenti: superarne una non ne scavalca
    // un'altra.
    testCity();
    enableTurnstile();
    turnstileAnswers(true);

    $this->post('/proponi-evento', validSubmission([
        Honeypot::FIELD => 'https://spam.example',
        Turnstile::FIELD => 'gettone-buono',
    ]))->assertSessionHasErrors(Honeypot::FIELD);

    expect(EventSubmission::query()->count())->toBe(0);
});
