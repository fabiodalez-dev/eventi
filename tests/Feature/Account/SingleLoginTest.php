<?php

declare(strict_types=1);

use App\Models\Organizer;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\Support\VenueIsolationScenario;

/**
 * Il login unico: una porta sola, `/accedi`, per tutti — redazione e gestori
 * compresi. I pannelli non hanno più una pagina di accesso loro: chi non è
 * dentro arriva qui e poi torna dove stava andando, chi esce da un pannello
 * torna qui, chi è dentro ma non ha titolo riceve un 403 che lo dice.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
});

function singleLoginOrganizer(User $owner): Organizer
{
    return Organizer::query()->create([
        'name' => 'Collettivo Portici',
        'city_id' => test()->scenario->city->id,
        'owner_id' => $owner->id,
        'is_active' => true,
    ]);
}

it('manda l ospite al login unico e lo riporta alla pagina del pannello da cui era partito', function (string $panel): void {
    $s = $this->scenario;
    $organizer = singleLoginOrganizer($s->ownerB);

    [$path, $user] = match ($panel) {
        'admin' => ['/admin/venues', $s->admin],
        'venue' => ['/gestione/'.$s->venueA->slug.'/eventi', $s->ownerA],
        'organizer' => ['/organizza/'.$organizer->slug, $s->ownerB],
    };

    $this->get($path)->assertRedirect(route('login'));
    $this->get(route('login'))->assertOk()->assertSee('data-login-panel="'.$panel.'"', false);

    $this->post(route('account.login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(url($path));
    $this->assertAuthenticatedAs($user);
    $this->get($path)->assertOk();
})->with(['admin', 'venue', 'organizer']);

it('rimanda le vecchie pagine di accesso e di recupero dei pannelli al sito', function (string $path): void {
    $this->get('/'.$path.'/login')->assertRedirect(route('login', ['intended' => url('/'.$path)]));
    $this->get('/'.$path.'/password-reset/request')->assertRedirect(route('account.password.request'));
    $this->get('/'.$path.'/password-reset/reset?token=gettone&email=persona%40example.test&signature=x')
        ->assertRedirect(route('account.password.reset', ['token' => 'gettone', 'email' => 'persona@example.test']));
})->with(['admin', 'gestione', 'organizza']);

it('porta nel pannello chi apre una vecchia pagina di accesso quando è già dentro', function (): void {
    $this->actingAs($this->scenario->admin)
        ->get(route('login', ['intended' => url('/admin')]))
        ->assertRedirect(url('/admin'));

    $this->actingAs($this->scenario->ownerA)
        ->get(route('login', ['intended' => url('/gestione')]))
        ->assertRedirect(url('/gestione'));
});

it('esce da un pannello verso il login unico, da cui entra anche la redazione', function (): void {
    $s = $this->scenario;

    $this->actingAs($s->ownerA)
        ->post('/gestione/logout')
        ->assertRedirect(route('login'));
    $this->assertGuest();

    $this->get(route('login'))->assertOk()->assertSee(__('account.login.logged_out'));

    $this->post(route('account.login.store'), ['email' => $s->admin->email, 'password' => 'password'])
        ->assertRedirect(url('/admin'));
    $this->assertAuthenticatedAs($s->admin);

    $this->post('/admin/logout')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('risponde 403 con il testo del pannello e la via d uscita a chi è dentro senza titolo', function (string $path, string $key): void {
    config(['app.debug' => false]);

    $this->actingAs($this->scenario->plainUser)
        ->get($path)
        ->assertForbidden()
        ->assertSee(e(__($key)), false)
        ->assertSee(__('errors.actions.area'))
        ->assertSee(route('account.logout'), false);
})->with([
    'redazione' => ['/admin', 'auth.panel.forbidden.admin'],
    'locali' => ['/gestione', 'auth.panel.forbidden.venue'],
    'organizzatori' => ['/organizza', 'auth.panel.forbidden.organizer'],
]);

it('lascia 404 il locale di un altro a chi gestisce il proprio', function (): void {
    config(['app.debug' => false]);

    $this->actingAs($this->scenario->ownerA)
        ->get('/gestione/'.$this->scenario->venueB->slug)
        ->assertNotFound();
});

it('riporta al modulo con il tempo d attesa quando i tentativi sono troppi', function (): void {
    config()->set('api.rate_limit.attempts_per_account', 2);
    $user = $this->scenario->plainUser;

    foreach (['198.51.100.1', '198.51.100.2'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('account.login.store'), ['email' => $user->email, 'password' => 'sbagliata'])
            ->assertSessionHasErrors('email');
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.3'])
        ->post(route('account.login.store'), ['email' => $user->email, 'password' => 'sbagliata'])
        ->assertRedirect(route('login'))
        ->assertHeader('Retry-After')
        ->assertSessionHasErrors('form')
        ->assertSessionHasInput('email', $user->email);
    expect($response->headers->get('Retry-After'))->toBeNumeric()
        ->and((string) session('errors')->first('form'))->toContain('Riprova fra');
    $this->assertGuest();

    /* Il modulo torna con il riquadro del motivo e l'indirizzo già scritto. */
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])
        ->followingRedirects()
        ->post(route('account.login.store'), ['email' => $user->email, 'password' => 'sbagliata'])
        ->assertOk()
        ->assertSee('data-form-errors', false)
        ->assertSee(__('auth.form.title'))
        ->assertSee('value="'.$user->email.'"', false);
});

it('riporta al modulo con un messaggio quando la sessione è scaduta', function (string $route, string $target, array $query, array $input): void {
    $request = Request::create(route($route), 'POST', $input);
    $request->setLaravelSession(app('session.store'));
    $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));

    $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException);

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(route($target, $query))
        ->and(app('session.store')->get('errors')?->first('form'))->toBe(__('auth.form.expired'))
        ->and(app('session.store')->get('_old_input'))->not->toHaveKey('password');
})->with([
    'accesso' => ['account.login.store', 'login', [], ['email' => 'a@example.test', 'password' => 'segreta']],
    'registrazione' => ['account.register.store', 'account.register', [], ['email' => 'a@example.test', 'password' => 'segreta']],
    'collegamento' => ['account.magic-link.store', 'account.magic-link', [], ['email' => 'a@example.test']],
    'password dimenticata' => ['account.password.email', 'account.password.request', [], ['email' => 'a@example.test']],
    'nuova password' => ['account.password.update', 'account.password.reset', ['token' => 'gettone', 'email' => 'a@example.test'], ['email' => 'a@example.test', 'token' => 'gettone', 'password' => 'segreta']],
]);

it('lascia il 419 e il 429 in JSON alle richieste dell app', function (): void {
    $request = Request::create(route('account.login.store'), 'POST', ['email' => 'a@example.test'], server: ['HTTP_ACCEPT' => 'application/json']);
    $request->setLaravelSession(app('session.store'));
    $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));

    expect(app(ExceptionHandler::class)->render($request, new TokenMismatchException)->getStatusCode())->toBe(419);
});

it('ha le dodici pagine di accesso nella colonna di servizio', function (): void {
    foreach ([route('login'), route('account.register'), route('account.magic-link'), route('account.password.request'), route('account.password.reset', ['token' => 't', 'email' => 'a@example.test'])] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        expect($html)->toContain('data-service-layout')
            ->and($html)->toContain('data-service-page')
            ->and(substr_count($html, '<h1'))->toBe(1);
    }
});
