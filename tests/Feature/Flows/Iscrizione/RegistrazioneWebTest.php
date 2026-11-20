<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\AccountAlreadyRegistered;
use App\Notifications\RegistrationLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * La registrazione dal sito vista da chi compila il modulo (§15.2, D64): i
 * dati sbagliati non creano niente e non spediscono niente, l'indirizzo è lo
 * stesso comunque lo si scriva, e il collegamento che arriva per posta porta
 * dove la persona stava andando.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    Notification::fake();
    /* Senza onboarding della community la conferma porta alla destinazione:
       è quella che questi flussi seguono. */
    config(['community.enabled' => false]);
});

/** Il collegamento dell'ultima registrazione spedita a quell'indirizzo. */
function iscrizioneLinkRegistrazione(string $email): string
{
    $user = User::query()->where('email', $email)->firstOrFail();
    $notifica = Notification::sent($user, RegistrationLink::class)->last();

    expect($notifica)->toBeInstanceOf(RegistrationLink::class);

    return $notifica->url;
}

it('respinge il modulo vuoto campo per campo, conserva quanto scritto e non crea né spedisce niente', function (): void {
    $this->get('/registrati')->assertOk();

    $this->from('/registrati')->post('/registrati', [
        'first_name' => 'Elena',
        'email' => '',
        'password' => '',
    ])->assertRedirect('/registrati')
        ->assertSessionHasErrors(['email', 'password'])
        ->assertSessionHasInput('first_name', 'Elena');

    /* Il modulo torna con il nome già scritto e il motivo sotto i campi. */
    $this->get('/registrati')->assertOk()->assertSee('value="Elena"', false);

    expect(User::query()->count())->toBe(0);
    Notification::assertNothingSent();
    $this->assertGuest();
});

it('rifiuta un indirizzo scritto male e una password corta o non ripetuta, poi accetta i dati corretti', function (): void {
    $this->from('/registrati')->post('/registrati', [
        'email' => 'non-e-un-indirizzo',
        'password' => 'una-password-molto-lunga',
        'password_confirmation' => 'una-password-molto-lunga',
    ])->assertRedirect('/registrati')->assertSessionHasErrors('email')->assertSessionDoesntHaveErrors('password');

    $this->from('/registrati')->post('/registrati', [
        'email' => 'sara.corta@example.test',
        'password' => 'corta',
        'password_confirmation' => 'corta',
    ])->assertSessionHasErrors('password');

    $this->from('/registrati')->post('/registrati', [
        'email' => 'sara.corta@example.test',
        'password' => 'una-password-molto-lunga',
        'password_confirmation' => 'una-password-diversa-lunga',
    ])->assertSessionHasErrors('password');

    /* La password non torna mai indietro nel modulo, nemmeno sbagliata. */
    expect(session()->getOldInput('password'))->toBeNull();

    expect(User::query()->count())->toBe(0);
    Notification::assertNothingSent();

    $this->post('/registrati', [
        'email' => 'sara.corta@example.test',
        'password' => 'una-password-molto-lunga',
        'password_confirmation' => 'una-password-molto-lunga',
    ])->assertRedirect(route('account.register.sent'))->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'sara.corta@example.test')->count())->toBe(1);
    Notification::assertSentTo(User::query()->where('email', 'sara.corta@example.test')->firstOrFail(), RegistrationLink::class);
});

it('riconosce un indirizzo già registrato anche scritto con le maiuscole e non crea un doppione', function (): void {
    $esistente = User::factory()->create(['email' => 'paola.verdi@example.test', 'password' => Hash::make('password-di-paola')]);

    $this->post('/registrati', [
        'email' => 'Paola.VERDI@Example.TEST',
        'password' => 'un-altra-password-lunga',
        'password_confirmation' => 'un-altra-password-lunga',
    ])->assertRedirect(route('account.register.sent'))
        ->assertSessionHas('status', __('account.register.sent'));

    expect(User::query()->count())->toBe(1)
        ->and(Hash::check('password-di-paola', (string) $esistente->fresh()->password))->toBeTrue();

    Notification::assertSentTo($esistente, AccountAlreadyRegistered::class);
    Notification::assertNotSentTo($esistente, RegistrationLink::class);

    /* La titolare apre il messaggio ed entra nel suo account di sempre. */
    $this->get(Notification::sent($esistente, AccountAlreadyRegistered::class)->first()->url)
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($esistente);
});

it('accetta un indirizzo nuovo scritto con maiuscole e spazi, e poi fa entrare scrivendolo in minuscolo', function (): void {
    $this->post('/registrati', [
        'email' => '  Marta.Rossi@Example.TEST  ',
        'password' => 'password-di-marta-lunga',
        'password_confirmation' => 'password-di-marta-lunga',
    ])->assertRedirect(route('account.register.sent'));

    $marta = User::query()->where('email', 'marta.rossi@example.test')->firstOrFail();
    /* Gli spazi intorno spariscono: dentro l'indirizzo non ci sono. */
    expect(trim((string) $marta->email))->toBe((string) $marta->email);

    $this->get(Notification::sent($marta, RegistrationLink::class)->first()->url)
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($marta);

    $this->post('/esci');
    $this->assertGuest();

    $this->post('/accedi', ['email' => 'marta.rossi@example.test', 'password' => 'password-di-marta-lunga'])
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($marta);
});

it('compone il nome da nome e cognome, e lascia vuoto il nome fatto di soli spazi', function (): void {
    $this->post('/registrati', [
        'first_name' => '  Anna ',
        'last_name' => ' Bassi  ',
        'email' => 'anna.bassi@example.test',
        'password' => 'password-di-anna-lunga',
        'password_confirmation' => 'password-di-anna-lunga',
    ])->assertRedirect(route('account.register.sent'));

    $this->flushSession();

    $this->post('/registrati', [
        'name' => '   ',
        'email' => 'senza.nome@example.test',
        'password' => 'password-senza-nome',
        'password_confirmation' => 'password-senza-nome',
    ])->assertRedirect(route('account.register.sent'));

    $anna = User::query()->where('email', 'anna.bassi@example.test')->firstOrFail();
    $anonimo = User::query()->where('email', 'senza.nome@example.test')->firstOrFail();

    expect($anna->first_name)->toBe('Anna')
        ->and($anna->last_name)->toBe('Bassi')
        ->and($anna->name)->toBe('Anna Bassi')
        ->and($anonimo->name)->toBeNull()
        ->and($anonimo->first_name)->toBeNull();

    /* Anna apre il messaggio in un browser che non è quello della
       registrazione: conferma con il pulsante e vede il proprio nome. */
    $this->post(iscrizioneLinkRegistrazione('anna.bassi@example.test'))->assertRedirect(route('account.feed'));
    $this->get('/il-mio-profilo')->assertOk()->assertSee('Anna Bassi');
});

it('non registra il consenso alla newsletter quando la newsletter è spenta, anche se il campo arriva', function (): void {
    config(['pennant.defaults.newsletter' => false]);

    /* Il modulo non mostra la casella. */
    $this->get('/registrati')->assertOk()->assertDontSee('name="marketing_opt_in"', false);

    $this->post('/registrati', [
        'email' => 'senza.newsletter@example.test',
        'password' => 'password-senza-newsletter',
        'password_confirmation' => 'password-senza-newsletter',
        'marketing_opt_in' => '1',
    ])->assertRedirect(route('account.register.sent'));

    $user = User::query()->where('email', 'senza.newsletter@example.test')->firstOrFail();
    expect($user->marketing_opt_in_at)->toBeNull();

    $this->get(iscrizioneLinkRegistrazione('senza.newsletter@example.test'))->assertRedirect();
    expect($user->fresh()->marketing_opt_in_at)->toBeNull();
});

it('rimanda all area personale chi apre la registrazione quando è già dentro', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/registrati')->assertRedirect(route('account.feed'));
    $this->actingAs($user)->post('/registrati', [
        'email' => 'un.altro@example.test',
        'password' => 'password-di-un-altro',
        'password_confirmation' => 'password-di-un-altro',
    ])->assertRedirect(route('account.feed'));

    /* Da dentro non nasce un secondo account. */
    expect(User::query()->where('email', 'un.altro@example.test')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('dopo la conferma riporta alla pagina da cui si era partiti per registrarsi', function (): void {
    $destinazione = url('/i-miei-salvataggi');

    $this->get('/registrati?intended='.urlencode($destinazione))
        ->assertOk()
        /* «Ho già un account» porta la stessa destinazione all'accesso. */
        ->assertSee(e(route('login', ['intended' => $destinazione])), false);

    $this->post('/registrati', [
        'email' => 'torna.agli.eventi@example.test',
        'password' => 'password-per-tornare',
        'password_confirmation' => 'password-per-tornare',
    ])->assertRedirect(route('account.register.sent'));

    $this->get(iscrizioneLinkRegistrazione('torna.agli.eventi@example.test'))
        ->assertRedirect($destinazione)
        ->assertSessionHas('status', __('account.verify.done'));

    $this->assertAuthenticated();
});

it('tiene collegato a lungo solo chi ha spuntato resta collegato al momento della registrazione', function (): void {
    $recaller = Auth::guard('web')->getRecallerName();

    $this->post('/registrati', [
        'email' => 'resta.collegata@example.test',
        'password' => 'password-resta-collegata',
        'password_confirmation' => 'password-resta-collegata',
        'remember' => '1',
    ])->assertRedirect(route('account.register.sent'));

    $this->get(iscrizioneLinkRegistrazione('resta.collegata@example.test'))->assertCookie($recaller);
    $this->post('/esci');

    $this->flushSession();
    $this->post('/registrati', [
        'email' => 'sessione.breve@example.test',
        'password' => 'password-sessione-breve',
        'password_confirmation' => 'password-sessione-breve',
    ])->assertRedirect(route('account.register.sent'));

    $this->get(iscrizioneLinkRegistrazione('sessione.breve@example.test'))->assertCookieMissing($recaller);
    $this->assertAuthenticatedAs(User::query()->where('email', 'sessione.breve@example.test')->firstOrFail());
});

it('risponde come sempre all indirizzo di un account cancellato, senza ridargli vita né scrivergli', function (): void {
    $cancellato = User::factory()->create(['email' => 'andata.via@example.test']);
    $cancellato->delete();

    $this->post('/registrati', [
        'email' => 'andata.via@example.test',
        'password' => 'password-di-ritorno',
        'password_confirmation' => 'password-di-ritorno',
    ])->assertRedirect(route('account.register.sent'))
        ->assertSessionHas('status', __('account.register.sent'));

    expect(User::withTrashed()->where('email', 'andata.via@example.test')->count())->toBe(1)
        ->and($cancellato->fresh()->trashed())->toBeTrue();
    Notification::assertNothingSent();

    /* Con la password nuova non entra: l'account resta cancellato. */
    $this->post('/accedi', ['email' => 'andata.via@example.test', 'password' => 'password-di-ritorno'])
        ->assertSessionHasErrors(['email' => __('account.login.failed')]);
    $this->assertGuest();
});

it('smette di spedire messaggi di registrazione alla stessa casella oltre il tetto orario, con la risposta di sempre', function (): void {
    foreach (range(1, 6) as $volta) {
        $this->flushSession();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$volta])
            ->post('/registrati', [
                'email' => 'insistente@example.test',
                'password' => 'password-insistente',
                'password_confirmation' => 'password-insistente',
            ])->assertRedirect(route('account.register.sent'));
    }

    $user = User::query()->where('email', 'insistente@example.test')->sole();

    /* Cinque messaggi in un'ora per account, da qualunque indirizzo arrivino. */
    Notification::assertSentToTimes($user, RegistrationLink::class, 5);

    /* Un'ora dopo il messaggio riparte. */
    $this->travel(61)->minutes();
    $this->flushSession();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->post('/registrati', [
            'email' => 'insistente@example.test',
            'password' => 'password-insistente',
            'password_confirmation' => 'password-insistente',
        ])->assertRedirect(route('account.register.sent'));

    Notification::assertSentToTimes($user, RegistrationLink::class, 6);
});

it('lascia salvare il profilo a chi si è registrato con nome e cognome lunghi', function (): void {
    $nome = str_repeat('Maria ', 12).'Luisa';
    $cognome = 'De '.str_repeat('Santis ', 8).'Roveri';

    $this->post('/registrati', [
        'first_name' => $nome,
        'last_name' => $cognome,
        'email' => 'nome.lungo@example.test',
        'password' => 'password-nome-lungo',
        'password_confirmation' => 'password-nome-lungo',
    ])->assertRedirect(route('account.register.sent'))->assertSessionHasNoErrors();

    $this->get(iscrizioneLinkRegistrazione('nome.lungo@example.test'))->assertRedirect(route('account.feed'));
    $user = User::query()->where('email', 'nome.lungo@example.test')->firstOrFail();
    expect(mb_strlen((string) $user->name))->toBeGreaterThan(120);

    /* Il profilo arriva con il nome già scritto: cambiare solo il fuso deve
       bastare, senza che il nome dato alla registrazione diventi un errore. */
    $this->get('/il-mio-profilo')->assertOk();
    $this->from('/il-mio-profilo')->patch('/il-mio-profilo', [
        'profile_only' => '1',
        'name' => $user->name,
        'timezone' => 'Europe/London',
        'locale' => 'it',
    ])->assertRedirect(route('account.profile'))->assertSessionHasNoErrors();
    expect($user->fresh()->timezone)->toBe('Europe/London')
        ->and($user->fresh()->name)->toBe($user->name);

    /* Anche dall'app lo stesso nome si rimanda indietro senza errori. */
    $this->withToken($user->createToken('Telefono')->plainTextToken)
        ->patchJson('/api/v1/me', ['name' => $user->name, 'timezone' => 'Europe/Rome'])
        ->assertOk()->assertJsonPath('data.name', $user->name);
});
