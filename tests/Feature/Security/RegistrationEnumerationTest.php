<?php

declare(strict_types=1);

use App\Filament\Auth\Login;
use App\Models\MobileAuthChallenge;
use App\Models\User;
use App\Notifications\AccountAlreadyRegistered;
use App\Notifications\MagicLoginLink;
use App\Notifications\RegistrationLink;
use App\Notifications\VerifyEmailLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * La registrazione non rivela se un indirizzo è già registrato (D64).
 *
 * Prima `POST /v1/auth/register` rispondeva 201 con il token per un indirizzo
 * nuovo e 422 per uno già usato, e il sito faceva lo stesso: bastava provare
 * un indirizzo per sapere se era iscritto. Ora la risposta è sempre «ti
 * abbiamo scritto»; per un indirizzo nuovo arriva il collegamento che
 * conferma ed entra, per uno già registrato «hai già un account». La password
 * scelta alla registrazione resta valida quando il collegamento lo apre chi
 * si è registrato (stesso browser, stessa installazione dell'app).
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    Notification::fake();
});

const SFIDA_VERIFICATORE = 'verificatore-di-prova-lungo-almeno-43-caratteri-abc';

function sfidaPkce(string $verificatore = SFIDA_VERIFICATORE): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verificatore, true)), '+/', '-_'), '=');
}

/** @return array<string, mixed> */
function datiRegistrazione(string $email, string $password = 'Password-Scelta-123', array $altro = []): array
{
    return [
        'first_name' => 'Giulia',
        'last_name' => 'Rossi',
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $password,
        'device_name' => 'Telefono di Giulia',
        'code_challenge' => sfidaPkce(),
        ...$altro,
    ];
}

function gettoneDalCollegamento(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) ($query['token'] ?? '');
}

function utenteEsistente(string $email = 'gia@example.test'): User
{
    return User::factory()->create([
        'email' => $email,
        'name' => 'Nome di sempre',
        'first_name' => 'Nome',
        'last_name' => 'Di sempre',
        'password' => Hash::make('password-di-sempre-1'),
    ]);
}

describe('API', function (): void {
    it('risponde allo stesso modo a un indirizzo nuovo e a uno già registrato, senza token', function (): void {
        utenteEsistente();

        $nuovo = $this->postJson('/api/v1/auth/register', datiRegistrazione('nuovo@example.test'));
        $esistente = $this->postJson('/api/v1/auth/register', datiRegistrazione('gia@example.test'));

        $nuovo->assertStatus(202);
        $esistente->assertStatus(202);
        expect($nuovo->json())->toBe($esistente->json())
            ->and($nuovo->json())->toBe(['data' => ['message' => __('api.auth.registration_sent')]])
            ->and($nuovo->json('data.token'))->toBeNull()
            ->and(User::query()->where('email', 'nuovo@example.test')->firstOrFail()->tokens()->count())->toBe(0)
            ->and(User::query()->where('email', 'gia@example.test')->firstOrFail()->tokens()->count())->toBe(0);
    });

    it('a un indirizzo nuovo manda il collegamento che conferma, a uno esistente «hai già un account»', function (): void {
        $esistente = utenteEsistente();

        $this->postJson('/api/v1/auth/register', datiRegistrazione('nuovo@example.test'))->assertStatus(202);
        $this->postJson('/api/v1/auth/register', datiRegistrazione('gia@example.test'))->assertStatus(202);

        $nuovo = User::query()->where('email', 'nuovo@example.test')->firstOrFail();
        Notification::assertSentTo($nuovo, RegistrationLink::class, fn (RegistrationLink $n): bool => str_contains($n->url, '/app/auth/magic?token='));
        Notification::assertNotSentTo($nuovo, AccountAlreadyRegistered::class);
        Notification::assertNotSentTo($nuovo, VerifyEmailLink::class);
        Notification::assertSentTo($esistente, AccountAlreadyRegistered::class, fn (AccountAlreadyRegistered $n): bool => str_contains($n->url, '/app/auth/magic?token='));
        Notification::assertNotSentTo($esistente, RegistrationLink::class);

        expect(MobileAuthChallenge::query()->where('user_id', $nuovo->id)->value('keeps_password'))->toBeTrue()
            ->and(MobileAuthChallenge::query()->where('user_id', $esistente->id)->value('keeps_password'))->toBeFalse();
    });

    it('non tocca l account già registrato', function (): void {
        $esistente = utenteEsistente();
        $prima = $esistente->fresh()->only(['password', 'name', 'first_name', 'last_name', 'marketing_opt_in_at', 'email_verified_at', 'email_claim_pending_at', 'remember_token']);
        $esistente->createToken('tablet');

        $this->postJson('/api/v1/auth/register', datiRegistrazione('gia@example.test', 'Altra-Password-999', ['marketing_opt_in' => true]))
            ->assertStatus(202);

        $dopo = $esistente->fresh();
        expect($dopo->only(array_keys($prima)))->toEqual($prima)
            ->and($dopo->tokens()->count())->toBe(1)
            ->and(User::query()->where('email', 'gia@example.test')->count())->toBe(1);
        $this->postJson('/api/v1/auth/login', ['email' => 'gia@example.test', 'password' => 'password-di-sempre-1'])->assertOk();
    });

    it('lascia gli errori di validazione dei campi uguali per ogni indirizzo', function (): void {
        utenteEsistente();

        $nuovo = $this->postJson('/api/v1/auth/register', datiRegistrazione('nuovo@example.test', 'corta'))->assertStatus(422);
        $esistente = $this->postJson('/api/v1/auth/register', datiRegistrazione('gia@example.test', 'corta'))->assertStatus(422);

        expect(array_keys($nuovo->json('error.fields')))->toBe(['password'])
            ->and($nuovo->json('error'))->toBe($esistente->json('error'))
            ->and(User::query()->where('email', 'nuovo@example.test')->exists())->toBeFalse();
        Notification::assertNothingSent();
    });

    it('lo scambio del collegamento dalla stessa installazione entra, conferma e non azzera la password', function (): void {
        $this->postJson('/api/v1/auth/register', datiRegistrazione('nuovo@example.test'))->assertStatus(202);
        $user = User::query()->where('email', 'nuovo@example.test')->firstOrFail();
        $url = Notification::sent($user, RegistrationLink::class)->first()->url;

        $token = $this->postJson('/api/v1/auth/magic-link/exchange', [
            'token' => gettoneDalCollegamento($url), 'code_verifier' => SFIDA_VERIFICATORE, 'device_name' => 'Telefono di Giulia',
        ])->assertOk()->json('data.token');

        $user->refresh();
        expect($token)->toBeString()
            ->and($user->hasVerifiedEmail())->toBeTrue()
            ->and($user->email_claim_pending_at)->toBeNull()
            ->and(Hash::check('Password-Scelta-123', (string) $user->password))->toBeTrue();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])
            ->postJson('/api/v1/auth/login', ['email' => 'nuovo@example.test', 'password' => 'Password-Scelta-123'])
            ->assertOk();
    });

    it('un intruso che registra l indirizzo di un altro non entra né con la password né con il collegamento', function (): void {
        $this->postJson('/api/v1/auth/register', datiRegistrazione('vittima@example.test', 'Password-Intruso-123'))->assertStatus(202);
        $user = User::query()->where('email', 'vittima@example.test')->firstOrFail();
        $url = Notification::sent($user, RegistrationLink::class)->first()->url;

        /* La password scelta non apre l'account finché la casella non è
           dimostrata. */
        $this->postJson('/api/v1/auth/login', ['email' => 'vittima@example.test', 'password' => 'Password-Intruso-123'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'EMAIL_CONFIRMATION_PENDING');

        /* La vittima apre il collegamento sul proprio telefono: senza il
           verificatore dell'intruso lo scambio non vale. */
        $this->postJson('/api/v1/auth/magic-link/exchange', [
            'token' => gettoneDalCollegamento($url), 'code_verifier' => str_repeat('x', 43),
        ])->assertStatus(400);

        expect($user->fresh()->tokens()->count())->toBe(0);
    });

    it('rifiuta l accesso con password a un account mai confermato e rimanda il collegamento', function (): void {
        $this->postJson('/api/v1/auth/register', datiRegistrazione('nuovo@example.test'))->assertStatus(202);
        $user = User::query()->where('email', 'nuovo@example.test')->firstOrFail();

        $this->postJson('/api/v1/auth/login', ['email' => 'nuovo@example.test', 'password' => 'sbagliata-davvero'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nuovo@example.test', 'password' => 'Password-Scelta-123', 'code_challenge' => sfidaPkce('un-altro-verificatore-lungo-almeno-43-caratteri'),
        ])->assertForbidden()->assertJsonPath('error.code', 'EMAIL_CONFIRMATION_PENDING');

        Notification::assertSentToTimes($user, RegistrationLink::class, 2);
        $url = Notification::sent($user, RegistrationLink::class)->last()->url;

        $this->postJson('/api/v1/auth/magic-link/exchange', [
            'token' => gettoneDalCollegamento($url), 'code_verifier' => 'un-altro-verificatore-lungo-almeno-43-caratteri',
        ])->assertOk();
        expect(Hash::check('Password-Scelta-123', (string) $user->fresh()->password))->toBeTrue();
    });

    it('rifare la registrazione con la stessa password rimanda il collegamento, con un altra no', function (): void {
        $this->postJson('/api/v1/auth/register', datiRegistrazione('nuovo@example.test'))->assertStatus(202);
        $user = User::query()->where('email', 'nuovo@example.test')->firstOrFail();
        $hash = $user->password;

        $this->postJson('/api/v1/auth/register', datiRegistrazione('nuovo@example.test'))->assertStatus(202);
        $this->postJson('/api/v1/auth/register', datiRegistrazione('nuovo@example.test', 'Tutta-Unaltra-456'))->assertStatus(202);

        Notification::assertSentToTimes($user, RegistrationLink::class, 2);
        Notification::assertSentToTimes($user, AccountAlreadyRegistered::class, 1);
        expect($user->fresh()->password)->toBe($hash);
    });

    it('mette un tetto ai messaggi verso la stessa casella', function (): void {
        $esistente = utenteEsistente();

        foreach (range(1, 6) as $volta) {
            $this->postJson('/api/v1/auth/register', datiRegistrazione('gia@example.test', 'Password-Tentativo-'.$volta))
                ->assertStatus(202)
                ->assertExactJson(['data' => ['message' => __('api.auth.registration_sent')]]);
        }

        /* Cinque messaggi l'ora per account; la risposta non cambia. */
        Notification::assertSentToTimes($esistente, AccountAlreadyRegistered::class, 5);

        /* E il tetto per casella delle rotte che spediscono resta davanti. */
        $this->postJson('/api/v1/auth/register', datiRegistrazione('gia@example.test'))->assertStatus(429);
    });
});

describe('sito', function (): void {
    it('mostra la stessa pagina «controlla la posta» a un indirizzo nuovo e a uno già registrato, senza entrare', function (): void {
        $esistente = utenteEsistente();

        $nuovo = $this->post(route('account.register.store'), datiRegistrazione('nuovo@example.test'));
        $this->assertGuest();
        $this->flushSession();
        $gia = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post(route('account.register.store'), datiRegistrazione('gia@example.test'));
        $this->assertGuest();

        $nuovo->assertRedirect(route('account.register.sent'))->assertSessionHas('status', __('account.register.sent'));
        $gia->assertRedirect(route('account.register.sent'))->assertSessionHas('status', __('account.register.sent'));
        $this->get(route('account.register.sent'))->assertOk()->assertSee(__('account.register.sent_title'));

        $user = User::query()->where('email', 'nuovo@example.test')->firstOrFail();
        expect($user->hasVerifiedEmail())->toBeFalse()->and($user->email_claim_pending_at)->not->toBeNull();
        Notification::assertSentTo($user, RegistrationLink::class);
        Notification::assertNotSentTo($user, VerifyEmailLink::class);
        Notification::assertSentTo($esistente, AccountAlreadyRegistered::class);
        expect(Hash::check('password-di-sempre-1', (string) $esistente->fresh()->password))->toBeTrue()
            ->and($esistente->fresh()->name)->toBe('Nome di sempre');
    });

    it('il collegamento aperto nello stesso browser entra, conferma e lascia valida la password scelta', function (): void {
        $this->post(route('account.register.store'), datiRegistrazione('sara@example.test'))->assertRedirect(route('account.register.sent'));
        $user = User::query()->where('email', 'sara@example.test')->firstOrFail();
        $url = Notification::sent($user, RegistrationLink::class)->first()->url;

        /* Appena confermato passa dall'onboarding della community, come prima. */
        $this->get($url)->assertRedirect(route('community.whatsapp'));

        $this->assertAuthenticatedAs($user);
        $user->refresh();
        expect($user->hasVerifiedEmail())->toBeTrue()
            ->and($user->email_claim_pending_at)->toBeNull()
            ->and(Hash::check('Password-Scelta-123', (string) $user->password))->toBeTrue();

        auth()->logout();
        $this->flushSession();
        $this->post(route('account.login.store'), ['email' => 'sara@example.test', 'password' => 'Password-Scelta-123'])
            ->assertRedirect(route('account.feed'));
        $this->assertAuthenticatedAs($user);
    });

    it('aperto in un altro browser entra ma azzera la password, che può averla scelta chiunque', function (): void {
        $this->post(route('account.register.store'), datiRegistrazione('vittima@example.test', 'Password-Intruso-123'));
        $user = User::query()->where('email', 'vittima@example.test')->firstOrFail();
        $url = Notification::sent($user, RegistrationLink::class)->first()->url;

        /* La vittima, nel proprio browser: prima la conferma, poi entra. */
        $this->flushSession();
        $this->get($url)->assertOk()->assertSee(__('account.magic.confirm_title'));
        $this->post($url)->assertRedirect(route('account.feed'));

        $this->assertAuthenticatedAs($user);
        $user->refresh();
        expect($user->hasVerifiedEmail())->toBeTrue()
            ->and($user->email_claim_pending_at)->toBeNull()
            ->and(Hash::check('Password-Intruso-123', (string) $user->password))->toBeFalse();
    });

    it('rifiuta l accesso con password a un account mai confermato e rimanda il collegamento legato al browser', function (): void {
        $this->post(route('account.register.store'), datiRegistrazione('luca@example.test'));
        $user = User::query()->where('email', 'luca@example.test')->firstOrFail();
        $this->flushSession();

        $this->post(route('account.login.store'), ['email' => 'luca@example.test', 'password' => 'sbagliata-davvero'])
            ->assertSessionHasErrors(['email' => __('account.login.failed')]);
        $this->post(route('account.login.store'), ['email' => 'luca@example.test', 'password' => 'Password-Scelta-123'])
            ->assertSessionHasErrors(['email' => __('account.login.claim_pending')]);
        $this->assertGuest();

        Notification::assertSentToTimes($user, RegistrationLink::class, 2);
        $url = Notification::sent($user, RegistrationLink::class)->last()->url;

        /* Aperto nel browser che ha dimostrato la password: entra e la tiene. */
        $this->get($url)->assertRedirect(route('community.whatsapp'));
        $this->assertAuthenticatedAs($user);
        expect(Hash::check('Password-Scelta-123', (string) $user->fresh()->password))->toBeTrue()
            ->and($user->fresh()->email_claim_pending_at)->toBeNull();
    });
});

/*
 * La seconda rilettura (D64): ripiego del collegamento dell'app, account
 * eliminati, chiave del tetto per casella, sfide create oltre il tetto,
 * pannelli, collegamento di accesso dopo la registrazione e account vecchi
 * mai confermati.
 */
describe('seconda rilettura', function (): void {
    it('la pagina di ripiego del collegamento dell app porta il collegamento nell app senza rifletterlo', function (): void {
        $gettone = str_repeat('Ab3', 21).'Z';

        $pagina = $this->get('/app/auth/magic?token='.$gettone)
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('intent://auth/magic?token=', false)
            ->assertSee('scheme=incitta;package=', false)
            ->assertSee('data-package="'.config('account.android_package').'"', false)
            ->assertDontSee($gettone, false)
            ->assertDontSee('accedere con la password');

        expect((string) $pagina->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('un account eliminato riceve la stessa risposta e nessun messaggio', function (): void {
        $eliminato = utenteEsistente('eliminato@example.test');
        $eliminato->delete();

        $api = $this->postJson('/api/v1/auth/register', datiRegistrazione('eliminato@example.test'))->assertStatus(202);
        expect($api->json())->toBe(['data' => ['message' => __('api.auth.registration_sent')]]);

        $this->post(route('account.register.store'), datiRegistrazione('eliminato@example.test'))
            ->assertRedirect(route('account.register.sent'));

        Notification::assertNothingSent();
        expect(User::withTrashed()->where('email', 'eliminato@example.test')->count())->toBe(1)
            ->and(MobileAuthChallenge::query()->count())->toBe(0);
    });

    it('il tetto per casella della registrazione scatta alla stessa richiesta per un indirizzo nuovo e per uno esistente', function (): void {
        config()->set('api.rate_limit.attempts_per_account', 3);
        utenteEsistente('gia@example.test');

        $accettate = function (string $email): int {
            $conto = 0;
            foreach (range(1, 6) as $volta) {
                $stato = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.(crc32($email) % 100 + $volta)])
                    ->postJson('/api/v1/auth/register', datiRegistrazione($email))->status();
                if ($stato === 429) {
                    break;
                }
                $conto++;
            }

            return $conto;
        };

        expect($accettate('nuovissimo@example.test'))->toBe(3)
            ->and($accettate('gia@example.test'))->toBe(3);
    });

    it('oltre il tetto dei messaggi non crea sfide e lascia valido il collegamento che il titolare ha in casella', function (): void {
        $titolare = utenteEsistente();
        MobileAuthChallenge::query()->create([
            'user_id' => $titolare->id, 'token_hash' => hash('sha256', str_repeat('t', 64)),
            'password_fingerprint' => MagicLoginLink::fingerprint($titolare), 'code_challenge' => sfidaPkce(),
            'expires_at' => now()->addMinutes(15),
        ]);
        foreach (range(1, 5) as $volta) {
            RateLimiter::hit('posta-registrazione:'.$titolare->id, 3600);
        }

        $this->postJson('/api/v1/auth/register', datiRegistrazione('gia@example.test', 'Password-Intruso-123', [
            'code_challenge' => sfidaPkce('verificatore-dell-intruso-lungo-almeno-43-caratteri'),
        ]))->assertStatus(202);

        Notification::assertNothingSent();
        expect(MobileAuthChallenge::query()->where('user_id', $titolare->id)->pluck('token_hash')->all())
            ->toBe([hash('sha256', str_repeat('t', 64))]);
    });

    it('anche l accesso a un account in sospeso oltre il tetto non tocca la sfida già spedita', function (): void {
        $this->postJson('/api/v1/auth/register', datiRegistrazione('nuovo@example.test'))->assertStatus(202);
        $user = User::query()->where('email', 'nuovo@example.test')->firstOrFail();
        $primo = gettoneDalCollegamento(Notification::sent($user, RegistrationLink::class)->first()->url);
        foreach (range(1, 4) as $volta) {
            RateLimiter::hit('posta-registrazione:'.$user->id, 3600);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nuovo@example.test', 'password' => 'Password-Scelta-123', 'code_challenge' => sfidaPkce('un-altro-verificatore-lungo-almeno-43-caratteri'),
        ])->assertForbidden()->assertJsonPath('error.code', 'EMAIL_CONFIRMATION_PENDING');

        Notification::assertSentToTimes($user, RegistrationLink::class, 1);
        $this->postJson('/api/v1/auth/magic-link/exchange', ['token' => $primo, 'code_verifier' => SFIDA_VERIFICATORE])->assertOk();
    });

    it('i pannelli non aprono con la password un account in sospeso', function (): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = User::factory()->create(['email_claim_pending_at' => now()]);
        $staff->assignRole('admin');

        Livewire::test(Login::class)->fillForm(['email' => $staff->email, 'password' => 'password'])->call('authenticate')
            ->assertHasErrors(['data.email']);
        $this->assertGuest();

        $staff->forceFill(['email_claim_pending_at' => null])->save();
        Livewire::test(Login::class)->fillForm(['email' => $staff->email, 'password' => 'password'])->call('authenticate')
            ->assertHasNoErrors();
        $this->assertAuthenticatedAs($staff);
    });

    it('il collegamento di accesso chiesto dallo stesso browser dopo la registrazione non azzera la password', function (): void {
        $this->post(route('account.register.store'), datiRegistrazione('elena@example.test'))->assertRedirect(route('account.register.sent'));
        $this->get(route('account.register.sent'))->assertOk()->assertSee(__('account.register.sent_again'));
        $user = User::query()->where('email', 'elena@example.test')->firstOrFail();

        $this->post(route('account.magic-link.store'), ['email' => 'elena@example.test'])->assertRedirect();
        $url = (string) Notification::sent($user, MagicLoginLink::class)->first()->toMail($user)->actionUrl;
        $this->get($url)->assertRedirect();

        $this->assertAuthenticatedAs($user);
        expect(Hash::check('Password-Scelta-123', (string) $user->fresh()->password))->toBeTrue()
            ->and($user->fresh()->email_claim_pending_at)->toBeNull();
    });

    it('il collegamento di accesso chiesto da un altro browser azzera ancora la password', function (): void {
        $this->post(route('account.register.store'), datiRegistrazione('vittima@example.test', 'Password-Intruso-123'));
        $user = User::query()->where('email', 'vittima@example.test')->firstOrFail();
        $this->flushSession();

        $this->post(route('account.magic-link.store'), ['email' => 'vittima@example.test']);
        $this->get((string) Notification::sent($user, MagicLoginLink::class)->first()->toMail($user)->actionUrl)->assertRedirect();

        expect(Hash::check('Password-Intruso-123', (string) $user->fresh()->password))->toBeFalse();
    });

    it('mette in sospeso gli account vecchi mai confermati, non quelli confermati né gli eliminati', function (): void {
        $vecchio = User::factory()->unverified()->create(['email' => 'vecchio@posta-vera.it', 'email_claim_pending_at' => null, 'password' => Hash::make('Password-Vecchia-1')]);
        // Un account di prova su un dominio che non riceve posta non si potrebbe mai confermare: resta com'è.
        $prova = User::factory()->unverified()->create(['email' => 'persona@demo.incitta.invalid', 'email_claim_pending_at' => null]);
        $confermato = User::factory()->create(['email_claim_pending_at' => null]);
        $eliminato = User::factory()->unverified()->create(['email_claim_pending_at' => null]);
        $eliminato->delete();

        (require database_path('migrations/2026_10_30_150100_backfill_email_claim_pending_at.php'))->up();

        expect($vecchio->fresh()->email_claim_pending_at?->timestamp)->toBe($vecchio->created_at?->timestamp)
            ->and($confermato->fresh()->email_claim_pending_at)->toBeNull()
            ->and($prova->fresh()->email_claim_pending_at)->toBeNull()
            ->and(User::withTrashed()->find($eliminato->id)?->email_claim_pending_at)->toBeNull();

        /* Ora la password da sola non apre, e rifare la registrazione con
           la stessa password rimanda il collegamento che conferma. */
        $this->postJson('/api/v1/auth/login', ['email' => 'vecchio@posta-vera.it', 'password' => 'Password-Vecchia-1'])
            ->assertForbidden()->assertJsonPath('error.code', 'EMAIL_CONFIRMATION_PENDING');
        $this->postJson('/api/v1/auth/register', datiRegistrazione('vecchio@posta-vera.it', 'Password-Vecchia-1'))->assertStatus(202);
        Notification::assertSentTo($vecchio, RegistrationLink::class);
        Notification::assertNotSentTo($vecchio, AccountAlreadyRegistered::class);
    });
});
