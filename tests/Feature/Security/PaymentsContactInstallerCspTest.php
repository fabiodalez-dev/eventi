<?php

declare(strict_types=1);

use App\Enums\ContactMode;
use App\Http\Controllers\Web\ImpersonationController;
use App\Mail\PublicContact;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Installer\InstallLock;
use App\Support\Csp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InstallerSandbox;

/*
 * Tre chiusure piccole della stessa revisione: il modulo di contatto vuole un
 * indirizzo confermato e ha un tetto per destinatario; la diagnosi
 * dell'installer non mostra il percorso assoluto del server; la CSP con nonce
 * copre anche le pagine pubbliche che iniziano come un pannello.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'contact_mode' => ContactMode::Members, 'contact_email' => 'venue-private@example.test']);
    // Un recapito già confermato dal doppio opt-in (ContactEmailOptInTest prova la conferma).
    $this->venue->markContactEmailConfirmed();
    $this->url = '/api/v1/venues/'.$this->venue->slug.'/contact';
    Mail::fake();
});

it('un account non confermato non scrive dal modulo di contatto via API, nemmeno in modalità iscritti', function (): void {
    Sanctum::actingAs(User::factory()->unverified()->create());

    $this->postJson($this->url, ['message' => 'Vorrei informazioni sull’accesso.'])
        ->assertForbidden()->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
    Mail::assertNothingSent();
});

it('un account non confermato non salta il captcha dove scrivono anche gli ospiti', function (): void {
    $this->venue->update(['contact_mode' => ContactMode::Everyone]);
    config(['contact.recaptcha_site_key' => 'test-site', 'contact.recaptcha_secret_key' => 'test-secret']);
    Http::fake();
    Sanctum::actingAs(User::factory()->unverified()->create(['email' => 'sindaco@comune.example']));

    $this->postJson($this->url, ['name' => 'Ufficio Stampa', 'email' => 'sindaco@comune.example', 'message' => 'Messaggio senza alcun captcha.'])->assertForbidden();
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

it('dal sito un account non confermato viene mandato a confermare l’indirizzo', function (): void {
    $unverified = User::factory()->unverified()->create();

    $this->actingAs($unverified)->post('/contatta/venues/'.$this->venue->slug, ['message' => 'Vorrei informazioni sul programma.'])
        ->assertRedirect(route('verification.notice'));
    Mail::assertNothingSent();
    $this->actingAs($unverified)->get('/locali/'.$this->venue->slug)->assertOk()->assertSee(__('community.email_required'));
});

it('un account confermato continua a scrivere senza captcha', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson($this->url, ['message' => 'Vorrei informazioni sull’accesso.'])->assertOk();
    Mail::assertSent(PublicContact::class, fn ($mail): bool => $mail->hasTo('venue-private@example.test'));
});

it('un recapito riceve al massimo trenta messaggi al giorno, anche ruotando gli account', function (): void {
    $owner = User::factory()->create();
    $organizer = Organizer::query()->create(['city_id' => $this->city->id, 'owner_id' => $owner->id, 'name' => 'Stesso recapito', 'slug' => 'stesso-recapito', 'is_active' => true,
        'contact_mode' => ContactMode::Members, 'contact_email' => 'VENUE-private@example.test']);
    $organizer->markContactEmailConfirmed();

    foreach (range(1, 10) as $account) {
        Sanctum::actingAs(User::factory()->create());
        foreach (range(1, 3) as $message) {
            $this->postJson($this->url, ['message' => "Messaggio {$message} dall’account {$account}."])->assertOk();
        }
    }

    /* Un errore sul campo con un messaggio che dice per quanto, non un 429
       nudo che l'app traduce in «attendi un minuto». */
    Sanctum::actingAs(User::factory()->create());
    $this->postJson($this->url, ['message' => 'Il trentunesimo messaggio del giorno.'])
        ->assertUnprocessable()->assertJsonPath('error.fields.message.0', __('contact.target_daily_limit'));
    /* Il tetto e' sul recapito, non sulla scheda: un'altra scheda con lo
       stesso indirizzo non lo aggira. */
    $this->postJson('/api/v1/organizers/'.$organizer->slug.'/contact', ['message' => 'Lo stesso recapito da un’altra scheda.'])
        ->assertUnprocessable()->assertJsonPath('error.fields.message.0', __('contact.target_daily_limit'));
    Mail::assertSentCount(30);

    $this->venue->update(['contact_email' => 'altro@example.test']);
    $this->venue->markContactEmailConfirmed();
    $this->postJson($this->url, ['message' => 'Un altro recapito ha il suo tetto.'])->assertOk();
});

it('un solo mittente non consuma il tetto comune di un recapito', function (): void {
    $spammer = User::factory()->create();
    Sanctum::actingAs($spammer);
    foreach (range(1, 3) as $message) {
        $this->postJson($this->url, ['message' => "Messaggio {$message} dallo stesso account."])->assertOk();
    }
    $this->postJson($this->url, ['message' => 'Il quarto messaggio dallo stesso account.'])
        ->assertUnprocessable()->assertJsonPath('error.fields.message.0', __('contact.sender_daily_limit'));

    /* Il recapito resta raggiungibile da chiunque altro. */
    Sanctum::actingAs(User::factory()->create());
    $this->postJson($this->url, ['message' => 'Un altro mittente scrive lo stesso.'])->assertOk();
    Mail::assertSentCount(4);
});

it('dal sito il tetto del recapito torna nel modulo con il testo scritto', function (): void {
    foreach (range(1, 10) as $account) {
        $sender = User::factory()->create();
        foreach (range(1, 3) as $message) {
            $this->actingAs($sender)->post('/contatta/venues/'.$this->venue->slug, ['message' => "Messaggio {$message} dall’account {$account}."])->assertRedirect();
        }
    }

    $this->actingAs(User::factory()->create())->from('/locali/'.$this->venue->slug)
        ->post('/contatta/venues/'.$this->venue->slug, ['message' => 'Un testo lungo che non deve andare perso.'])
        ->assertRedirect('/locali/'.$this->venue->slug)
        ->assertSessionHasErrors(['message' => __('contact.target_daily_limit')])
        ->assertSessionHasInput('message', 'Un testo lungo che non deve andare perso.');
    Mail::assertSentCount(30);
});

it('durante l’impersonificazione il modulo contatti e le richieste non partono a nome della persona', function (): void {
    $admin = User::factory()->create();
    $user = User::factory()->create();
    $session = [ImpersonationController::SESSION_KEY => $admin->id];
    $unclaimed = Venue::factory()->approved()->create(['city_id' => $this->city->id]);

    $this->actingAs($user)->withSession($session)
        ->post('/contatta/venues/'.$this->venue->slug, ['message' => 'Messaggio scritto dall’amministratore.'])->assertForbidden();
    $this->withSession($session)->post(route('venues.claim.store', $unclaimed), [])->assertForbidden();
    $this->withSession($session)->post(route('venue-applications.store'), [])->assertForbidden();
    $this->withSession($session)->post(route('organizer-applications.store'), [])->assertForbidden();
    Mail::assertNothingSent();

    /* Fuori dall'impersonificazione lo stesso account scrive normalmente. */
    $this->withSession([])->flushSession();
    $this->actingAs($user)->post('/contatta/venues/'.$this->venue->slug, ['message' => 'Messaggio scritto dalla persona.'])->assertRedirect();
    Mail::assertSentCount(1);
});

it('la diagnosi dell’installer non mostra il percorso assoluto del marcatore', function (): void {
    $sandbox = InstallerSandbox::make($this->app);

    try {
        app(InstallLock::class)->write('prova');
        InstallerSandbox::pretendUnreachableDatabase($this->app);

        $this->get('/installazione')->assertStatus(503)
            ->assertDontSee($sandbox->directory)
            ->assertSee(__('installer.diagnosis.lock', ['path' => 'install.lock']));

        /* Il marcatore vero sta sotto l'applicazione: in pagina resta il
           percorso relativo, che chi amministra riconosce. */
        $lock = new InstallLock(storage_path('app/private/payments-test-install.lock'));
        $this->app->instance(InstallLock::class, $lock);
        $lock->write('prova');

        $this->get('/installazione')->assertStatus(503)
            ->assertDontSee(base_path())
            ->assertSee('storage/app/private/payments-test-install.lock');
    } finally {
        @unlink(storage_path('app/private/payments-test-install.lock'));
        $sandbox->cleanup();
    }
});

it('esclude dalla CSP con nonce solo i pannelli, non le pagine pubbliche che iniziano allo stesso modo', function (string $path, bool $applies): void {
    expect(app(Csp::class)->appliesTo(Request::create($path)))->toBe($applies);
})->with([
    ['/organizzatori', true],
    ['/organizzatori/collettivo', true],
    ['/gestione-biglietti', true],
    ['/gestione-biglietti/12/scanner', true],
    ['/admin-community/cases/1/evidence', true],
    ['/admin', false],
    ['/admin/events', false],
    ['/gestione', false],
    ['/gestione/circolo/sponsorizzazioni', false],
    ['/organizza', false],
    ['/organizza/collettivo/eventi', false],
    ['/installazione', false],
    ['/installazione/requisiti', false],
]);

it('la scheda pubblica degli organizzatori riceve la script-src con nonce', function (): void {
    $politica = (string) $this->get('/organizzatori')->assertOk()->headers->get('Content-Security-Policy');

    expect($politica)->toContain("script-src 'self' 'nonce-")->toContain("'strict-dynamic'");
});
