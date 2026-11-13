<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\AccountAlreadyRegistered;
use App\Notifications\MagicLoginLink;
use App\Notifications\RegistrationLink;
use App\Notifications\ResetPasswordLink;
use App\Notifications\VerifyEmailLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * Flussi del sito: registrazione, conferma della casella, accesso con password
 * e con collegamento, password dimenticata e uscita (§15.2, D64).
 *
 * Ogni prova segue una persona per più passi, come farebbe dal browser: il
 * messaggio spedito si apre davvero, e lo stato del database si controlla
 * dopo ogni passo che dovrebbe cambiarlo.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    Notification::fake();
    /* La conferma della registrazione passa dall'onboarding della community
       quando è accesa: lo si dichiara, per non dipendere dall'ambiente. */
    config(['community.enabled' => true]);
    /* Il collegamento di reimpostazione deve portare alla pagina del sito. */
    config(['api.password_reset_url' => null]);
});

/** L'indirizzo dell'ultimo collegamento di accesso spedito a quell'account. */
function flowAccountLastMagicUrl(User $user): string
{
    $notifica = Notification::sent($user, MagicLoginLink::class)->last();

    expect($notifica)->toBeInstanceOf(MagicLoginLink::class);

    return (string) $notifica->toMail($user)->actionUrl;
}

/** Token e indirizzo dell'ultimo collegamento di reimpostazione spedito. */
function flowAccountResetQuery(User $user): array
{
    $notifica = Notification::sent($user, ResetPasswordLink::class)->last();

    expect($notifica)->toBeInstanceOf(ResetPasswordLink::class);

    parse_str((string) parse_url((string) $notifica->toMail($user)->actionUrl, PHP_URL_QUERY), $query);

    return $query;
}

it('si registra dal sito, apre il collegamento nello stesso browser, entra e poi rientra con la password scelta', function (): void {
    $this->get('/registrati')->assertOk();

    $this->post('/registrati', [
        'name' => 'Giulia Bianchi',
        'email' => 'giulia.flusso@example.test',
        'password' => 'una-password-molto-lunga',
        'password_confirmation' => 'una-password-molto-lunga',
    ])->assertRedirect(route('account.register.sent'))
        ->assertSessionHas('status', __('account.register.sent'));

    /* «Controlla la posta»: nessuna sessione aperta, e la pagina non ripete
       l'indirizzo. */
    $this->get(route('account.register.sent'))->assertOk()->assertDontSee('giulia.flusso@example.test');
    $this->assertGuest();

    $user = User::query()->where('email', 'giulia.flusso@example.test')->firstOrFail();

    expect($user->name)->toBe('Giulia Bianchi')
        ->and($user->hasRole('user'))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->email_claim_pending_at)->not->toBeNull();

    Notification::assertSentToTimes($user, RegistrationLink::class, 1);
    Notification::assertNotSentTo($user, VerifyEmailLink::class);

    /* Il collegamento aperto nel browser che si è registrato conferma ed
       entra con un clic, passando dall'onboarding della community. */
    $this->get(Notification::sent($user, RegistrationLink::class)->first()->url)
        ->assertRedirect(route('community.whatsapp'));
    $this->assertAuthenticatedAs($user);

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->email_claim_pending_at)->toBeNull()
        /* Chi ha scelto la password è chi ha aperto il collegamento: resta. */
        ->and(Hash::check('una-password-molto-lunga', (string) $user->password))->toBeTrue()
        ->and(DB::table('used_web_login_links')->count())->toBe(1);

    $this->post('/esci')->assertRedirect(route('home'));
    $this->assertGuest();

    $this->post('/accedi', ['email' => 'giulia.flusso@example.test', 'password' => 'una-password-molto-lunga'])
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);

    $this->get('/il-mio-feed')->assertOk();
});

it('risponde allo stesso modo a un indirizzo già registrato e manda il collegamento di accesso invece di un errore', function (): void {
    $esistente = User::factory()->create(['email' => 'esiste.flusso@example.test', 'name' => 'Chi c\'era']);
    $hashPrima = $esistente->password;
    $utentiPrima = User::query()->count();

    $nuova = $this->post('/registrati', [
        'email' => 'nuova.flusso@example.test',
        'password' => 'una-password-molto-lunga',
        'password_confirmation' => 'una-password-molto-lunga',
    ]);
    $giaUsata = $this->post('/registrati', [
        'name' => 'Intruso',
        'email' => 'esiste.flusso@example.test',
        'password' => 'un-altra-password-lunga',
        'password_confirmation' => 'un-altra-password-lunga',
    ]);

    /* Stessa destinazione, stesso messaggio: dalla risposta non si capisce
       quale dei due indirizzi era già iscritto. */
    $nuova->assertRedirect(route('account.register.sent'))->assertSessionHas('status', __('account.register.sent'));
    $giaUsata->assertRedirect(route('account.register.sent'))->assertSessionHas('status', __('account.register.sent'));
    expect($nuova->getStatusCode())->toBe($giaUsata->getStatusCode());

    $esistente->refresh();
    expect(User::query()->count())->toBe($utentiPrima + 1)
        ->and($esistente->password)->toBe($hashPrima)
        ->and($esistente->name)->toBe('Chi c\'era');

    Notification::assertSentTo($esistente, AccountAlreadyRegistered::class);
    Notification::assertNotSentTo($esistente, RegistrationLink::class);

    /* Il titolare apre il messaggio nello stesso browser: entra, e la sua
       password non viene toccata perché la casella era già dimostrata. */
    $this->get(Notification::sent($esistente, AccountAlreadyRegistered::class)->first()->url)
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($esistente);

    expect(Hash::check('password', (string) $esistente->fresh()->password))->toBeTrue();
});

it('chiede conferma se il collegamento si apre in un altro browser, e lì la password scelta alla registrazione si azzera', function (): void {
    $this->post('/registrati', [
        'email' => 'altro.browser@example.test',
        'password' => 'scelta-alla-registrazione',
        'password_confirmation' => 'scelta-alla-registrazione',
    ])->assertRedirect(route('account.register.sent'));

    $user = User::query()->where('email', 'altro.browser@example.test')->firstOrFail();
    $url = Notification::sent($user, RegistrationLink::class)->first()->url;

    /* Un browser pulito: il telefono, o qualcuno a cui il messaggio è
       stato girato. */
    session()->flush();
    $this->flushSession();

    $this->get($url)
        ->assertOk()
        ->assertSee(Str::mask('altro.browser', '*', 1).'@example.test')
        ->assertDontSee('altro.browser@example.test');
    $this->assertGuest();
    expect(DB::table('used_web_login_links')->count())->toBe(0);

    /* Il pulsante della pagina di conferma entra. */
    $this->post($url)->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->email_claim_pending_at)->toBeNull()
        /* Chi apre qui possiede la casella, ma la password può averla scelta
           chiunque: non vale più. */
        ->and(Hash::check('scelta-alla-registrazione', (string) $user->password))->toBeFalse();

    $this->post('/esci')->assertRedirect(route('home'));

    $this->post('/accedi', ['email' => 'altro.browser@example.test', 'password' => 'scelta-alla-registrazione'])
        ->assertSessionHasErrors(['email' => __('account.login.failed')]);
    $this->assertGuest();
});

it('tiene un account non confermato fuori dai salvataggi finché non conferma, e lo lascia entrare dopo il reinvio', function (): void {
    $user = User::factory()->unverified()->create([
        'email' => 'da.confermare@example.test',
        'password' => Hash::make('password-da-confermare'),
    ]);

    $this->post('/accedi', ['email' => 'da.confermare@example.test', 'password' => 'password-da-confermare'])
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);

    /* Salvataggi bloccati, profilo libero: si deve poter vedere e cancellare
       il proprio account anche senza confermare. */
    $this->get('/i-miei-salvataggi')->assertRedirect(route('verification.notice'));
    $this->get('/il-mio-profilo')->assertOk()->assertSee('da.confermare@example.test');

    $this->get('/email/verifica')->assertOk()->assertSee('da.confermare@example.test');

    $this->from('/email/verifica')->post('/email/verifica')
        ->assertRedirect('/email/verifica')
        ->assertSessionHas('status', __('account.verify.sent'));
    Notification::assertSentToTimes($user, VerifyEmailLink::class, 1);

    $collegamento = (string) Notification::sent($user, VerifyEmailLink::class)->first()->toMail($user)->actionUrl;

    $this->get($collegamento)->assertRedirect(route('community.whatsapp'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and($user->fresh()->canReceiveNotifications())->toBeTrue();

    $this->get('/i-miei-salvataggi')->assertOk();
    $this->get('/email/verifica')->assertRedirect(route('account.profile'));

    /* Confermato, il reinvio non spedisce più niente. */
    $this->post('/email/verifica')->assertRedirect();
    Notification::assertSentToTimes($user, VerifyEmailLink::class, 1);
});

it('rifiuta la password di un account appena registrato e non confermato, rispedendo il collegamento che poi la conserva', function (): void {
    $this->post('/registrati', [
        'email' => 'sospeso.flusso@example.test',
        'password' => 'password-del-sospeso',
        'password_confirmation' => 'password-del-sospeso',
    ])->assertRedirect(route('account.register.sent'));

    $user = User::query()->where('email', 'sospeso.flusso@example.test')->firstOrFail();
    Notification::assertSentToTimes($user, RegistrationLink::class, 1);

    /* Password sbagliata: la risposta di sempre, e nessun messaggio in più
       che riveli lo stato dell'account. */
    $this->post('/accedi', ['email' => 'sospeso.flusso@example.test', 'password' => 'non-e-lei'])
        ->assertSessionHasErrors(['email' => __('account.login.failed')]);
    Notification::assertSentToTimes($user, RegistrationLink::class, 1);

    /* Password giusta: non entra, ma riparte il collegamento. */
    $this->post('/accedi', ['email' => 'sospeso.flusso@example.test', 'password' => 'password-del-sospeso'])
        ->assertSessionHasErrors(['email' => __('account.login.claim_pending')]);
    $this->assertGuest();
    Notification::assertSentToTimes($user, RegistrationLink::class, 2);

    $this->get(Notification::sent($user, RegistrationLink::class)->last()->url)
        ->assertRedirect(route('community.whatsapp'));
    $this->assertAuthenticatedAs($user);

    expect($user->fresh()->email_claim_pending_at)->toBeNull()
        ->and($user->fresh()->hasVerifiedEmail())->toBeTrue();

    $this->post('/esci');
    $this->post('/accedi', ['email' => 'sospeso.flusso@example.test', 'password' => 'password-del-sospeso'])
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);
});

it('respinge credenziali sbagliate senza rivelare quali email esistono e poi riporta alla pagina chiesta', function (): void {
    $user = User::factory()->create([
        'email' => 'torna.dove@example.test',
        'password' => Hash::make('la-password-giusta'),
        'last_active_at' => now()->subMonth(),
    ]);

    /* Chi apre il profilo da ospite viene mandato all'accesso. */
    $this->get('/il-mio-profilo')->assertRedirect(route('login'));
    $this->get('/accedi')->assertOk();

    /* Lo stesso messaggio, la stessa risposta: email esistente con password
       sbagliata ed email mai vista non si distinguono. */
    $esistente = $this->from('/accedi')->post('/accedi', ['email' => 'torna.dove@example.test', 'password' => 'sbagliata'])
        ->assertRedirect('/accedi')
        ->assertSessionHasErrors(['email' => __('account.login.failed')]);

    $inesistente = $this->from('/accedi')->post('/accedi', ['email' => 'mai.vista.flusso@example.test', 'password' => 'sbagliata'])
        ->assertRedirect('/accedi')
        ->assertSessionHasErrors(['email' => __('account.login.failed')]);

    expect($esistente->getStatusCode())->toBe($inesistente->getStatusCode());
    $this->assertGuest();

    $this->post('/accedi', ['email' => 'torna.dove@example.test', 'password' => 'la-password-giusta'])
        ->assertRedirect(route('account.profile'));
    $this->assertAuthenticatedAs($user);

    expect($user->fresh()->last_active_at?->isToday())->toBeTrue();

    $this->get('/il-mio-profilo')->assertOk()->assertSee('torna.dove@example.test');
});

it('manda il collegamento di accesso, lo apre nello stesso browser con resta collegato e non lo fa valere due volte', function (): void {
    $user = User::factory()->create(['email' => 'collegamento.flusso@example.test']);

    $this->get('/accedi/collegamento')->assertOk();

    /* Indirizzo sconosciuto: stessa risposta, nessun messaggio. */
    $this->post('/accedi/collegamento', ['email' => 'nessuno.flusso@example.test'])
        ->assertRedirect(route('account.magic-link'))
        ->assertSessionHas('status', __('account.magic.sent'));
    Notification::assertNothingSent();

    $this->post('/accedi/collegamento', ['email' => 'collegamento.flusso@example.test', 'remember' => '1'])
        ->assertRedirect(route('account.magic-link'))
        ->assertSessionHas('status', __('account.magic.sent'));
    Notification::assertSentToTimes($user, MagicLoginLink::class, 1);
    Notification::assertCount(1);

    $url = flowAccountLastMagicUrl($user);

    $this->get($url)
        ->assertRedirect(route('account.feed'))
        ->assertCookie(Auth::guard('web')->getRecallerName());
    $this->assertAuthenticatedAs($user);
    expect(DB::table('used_web_login_links')->count())->toBe(1);

    $this->post('/esci')->assertRedirect(route('home'));
    $this->assertGuest();

    /* Lo stesso collegamento, riaperto: è già stato speso. */
    $this->get($url)->assertRedirect(route('account.magic-link'))->assertSessionHas('status', __('account.magic.expired'));
    $this->post($url)->assertRedirect(route('account.magic-link'));
    $this->assertGuest();
});

it('rifiuta un collegamento di accesso manomesso, scaduto o reso vecchio da un cambio di password', function (): void {
    $user = User::factory()->create(['email' => 'rifiuti.flusso@example.test']);

    $this->post('/accedi/collegamento', ['email' => 'rifiuti.flusso@example.test'])->assertRedirect();
    $url = flowAccountLastMagicUrl($user);

    /* Firma alterata: il middleware lo ferma prima del controller. */
    $this->get($url.'0')->assertForbidden();
    $this->assertGuest();

    /* La password cambia dopo la spedizione: il collegamento non vale più. */
    $user->forceFill(['password' => Hash::make('cambiata-nel-frattempo')])->save();
    $this->get($url)->assertRedirect(route('account.magic-link'))->assertSessionHas('status', __('account.magic.expired'));
    $this->assertGuest();
    expect(DB::table('used_web_login_links')->count())->toBe(0);

    /* Un collegamento nuovo, aperto troppo tardi. */
    $this->post('/accedi/collegamento', ['email' => 'rifiuti.flusso@example.test'])->assertRedirect();
    $nuovo = flowAccountLastMagicUrl($user);
    $this->travel(config()->integer('account.magic_link_minutes') + 1)->minutes();

    $this->get($nuovo)->assertForbidden();
    $this->post($nuovo)->assertForbidden();
    $this->assertGuest();
});

it('reimposta la password dimenticata dal collegamento ricevuto, butta fuori i dispositivi e fa entrare solo con quella nuova', function (): void {
    $user = User::factory()->create([
        'email' => 'smemorato.flusso@example.test',
        'password' => Hash::make('la-vecchia-password'),
    ]);
    $user->createToken('Telefono');
    $user->createToken('Tablet');

    $this->get('/password-dimenticata')->assertOk();

    $sconosciuto = $this->post('/password-dimenticata', ['email' => 'nessuno.flusso@example.test']);
    $conosciuto = $this->post('/password-dimenticata', ['email' => 'smemorato.flusso@example.test']);

    $sconosciuto->assertRedirect(route('account.password.request'))->assertSessionHas('status', __('account.forgot.sent'));
    $conosciuto->assertRedirect(route('account.password.request'))->assertSessionHas('status', __('account.forgot.sent'));
    Notification::assertSentTo($user, ResetPasswordLink::class);
    Notification::assertCount(1);

    $query = flowAccountResetQuery($user);
    expect($query['email'] ?? null)->toBe('smemorato.flusso@example.test');

    $this->get(route('account.password.reset', ['token' => $query['token'], 'email' => $query['email']]))
        ->assertOk()
        ->assertSee('smemorato.flusso@example.test');

    /* La conferma che non coincide non cambia niente. */
    $this->from(route('account.password.reset'))->post('/reimposta-password', [
        'token' => $query['token'],
        'email' => $query['email'],
        'password' => 'la-nuova-password-lunga',
        'password_confirmation' => 'diversa-da-quella',
    ])->assertSessionHasErrors('password');
    expect(Hash::check('la-vecchia-password', (string) $user->fresh()->password))->toBeTrue();

    $this->post('/reimposta-password', [
        'token' => $query['token'],
        'email' => $query['email'],
        'password' => 'la-nuova-password-lunga',
        'password_confirmation' => 'la-nuova-password-lunga',
    ])->assertRedirect(route('login'))->assertSessionHas('status', __('account.reset.done'));

    expect($user->tokens()->count())->toBe(0)
        ->and(Hash::check('la-nuova-password-lunga', (string) $user->fresh()->password))->toBeTrue();

    /* Un minuto dopo, per non contare questi tentativi con i precedenti. */
    $this->travel(61)->seconds();

    /* Il token è già stato speso. */
    $this->from(route('account.password.reset'))->post('/reimposta-password', [
        'token' => $query['token'],
        'email' => $query['email'],
        'password' => 'un-terzo-tentativo-lungo',
        'password_confirmation' => 'un-terzo-tentativo-lungo',
    ])->assertSessionHasErrors(['email' => __('account.reset.failed')]);

    $this->post('/accedi', ['email' => 'smemorato.flusso@example.test', 'password' => 'la-vecchia-password'])
        ->assertSessionHasErrors(['email' => __('account.login.failed')]);
    $this->assertGuest();

    $this->post('/accedi', ['email' => 'smemorato.flusso@example.test', 'password' => 'la-nuova-password-lunga'])
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);
});

it('chiude il sospeso di chi si è appena registrato quando reimposta la password dalla casella', function (): void {
    $this->post('/registrati', [
        'email' => 'reset.sospeso@example.test',
        'password' => 'scelta-e-dimenticata',
        'password_confirmation' => 'scelta-e-dimenticata',
    ])->assertRedirect(route('account.register.sent'));

    $user = User::query()->where('email', 'reset.sospeso@example.test')->firstOrFail();
    expect($user->email_claim_pending_at)->not->toBeNull();

    $this->post('/password-dimenticata', ['email' => 'reset.sospeso@example.test'])->assertRedirect();
    $query = flowAccountResetQuery($user);

    $this->post('/reimposta-password', [
        'token' => $query['token'],
        'email' => 'reset.sospeso@example.test',
        'password' => 'scelta-dalla-casella',
        'password_confirmation' => 'scelta-dalla-casella',
    ])->assertRedirect(route('login'));

    $user->refresh();
    /* Reimpostare prova il possesso della casella (D64): il sospeso è chiuso
       e l'indirizzo confermato. */
    expect($user->email_claim_pending_at)->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeTrue();

    $this->post('/accedi', ['email' => 'reset.sospeso@example.test', 'password' => 'scelta-dalla-casella'])
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);
    /* Nessun collegamento di registrazione rispedito all'accesso. */
    Notification::assertSentToTimes($user, RegistrationLink::class, 1);
});

it('esce dall account, perde l accesso all area personale e da ospite non può uscire di nuovo', function (): void {
    $user = User::factory()->create(['email' => 'esce.flusso@example.test', 'password' => Hash::make('password-per-uscire')]);

    $this->post('/accedi', ['email' => 'esce.flusso@example.test', 'password' => 'password-per-uscire'])
        ->assertRedirect(route('account.feed'));
    $this->get('/il-mio-profilo')->assertOk();

    $this->post('/esci')
        ->assertRedirect(route('home'))
        ->assertSessionHas('status', __('account.login.logged_out'));
    $this->assertGuest();

    $this->get('/il-mio-profilo')->assertRedirect(route('login'));
    $this->get('/il-mio-feed')->assertRedirect(route('login'));
    $this->post('/esci')->assertRedirect(route('login'));

    /* L'account resta intatto: uscire non è cancellarsi. */
    expect($user->fresh()?->trashed())->toBeFalse();
});
