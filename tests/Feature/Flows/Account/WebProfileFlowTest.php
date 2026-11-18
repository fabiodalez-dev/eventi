<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Models\Follow;
use App\Models\User;
use App\Notifications\RegistrationLink;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * Il profilo dal sito (§15.2, §15.9): modificarlo, scaricare i propri dati,
 * cancellare l'account con la password attuale, e la scelta dell'aspetto.
 * Ogni flusso comincia con un accesso vero, non con `actingAs`.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->city = testCity();
    $this->category = testCategory();
    Notification::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Accede dal modulo. Dove si arriva dipende da cosa si era chiesto da ospite:
 * il feed, se niente, altrimenti la pagina ricordata.
 */
function flowAccountWebLogin(string $email, string $password, ?string $destinazione = null): void
{
    test()->post('/accedi', ['email' => $email, 'password' => $password])
        ->assertRedirect($destinazione ?? route('account.feed'));
}

it('modifica nome, fuso e preferenze dal profilo e respinge un fuso inventato senza toccare niente', function (): void {
    $user = User::factory()->create([
        'name' => 'Nome Vecchio',
        'email' => 'profilo.flusso@example.test',
        'password' => Hash::make('password-del-profilo'),
    ]);

    /* Da ospite il modulo non si salva. */
    $this->patch('/il-mio-profilo', ['name' => 'Nessuno', 'timezone' => 'Europe/Rome', 'locale' => 'it'])
        ->assertRedirect(route('login'));
    expect($user->fresh()->name)->toBe('Nome Vecchio');

    /* Il modulo inviato da ospite ha lasciato come destinazione la pagina da
       cui era partito: la home. */
    flowAccountWebLogin('profilo.flusso@example.test', 'password-del-profilo', url('/'));

    $this->get('/il-mio-profilo')
        ->assertOk()
        ->assertSee('profilo.flusso@example.test')
        ->assertSee('Nome Vecchio');

    $this->patch('/il-mio-profilo', [
        'profile_only' => '1',
        'name' => 'Giulia Verdi',
        'timezone' => 'Europe/London',
        'locale' => 'it',
    ])->assertRedirect(route('account.profile'))->assertSessionHas('status', __('account.profile.saved'));

    $user->refresh();
    expect($user->name)->toBe('Giulia Verdi')
        ->and($user->timezone)->toBe('Europe/London');

    /* Un fuso che non esiste e una lingua non tradotta: errori, nessun
       cambiamento. */
    $this->from('/il-mio-profilo')->patch('/il-mio-profilo', [
        'profile_only' => '1',
        'name' => 'Da non salvare',
        'timezone' => 'Marte/Olympus',
        'locale' => 'en',
    ])->assertRedirect('/il-mio-profilo')->assertSessionHasErrors(['timezone', 'locale']);
    expect($user->fresh()->name)->toBe('Giulia Verdi');

    /* Il modulo completo: le caselle non spuntate valgono «spento». */
    $this->patch('/il-mio-profilo', [
        'name' => '',
        'timezone' => 'Europe/Rome',
        'locale' => 'it',
        'reminders' => '1',
        'daily_digest' => '1',
        'daily_digest_time' => '18:30',
        'quiet_from' => '23:00',
        'quiet_to' => '07:30',
    ])->assertRedirect(route('account.profile'));

    $user->refresh();
    $preferenze = $user->notificationPreferences();

    expect($user->name)->toBeNull()
        ->and($user->timezone)->toBe('Europe/Rome')
        ->and($preferenze->reminders)->toBeTrue()
        ->and($preferenze->dailyDigest)->toBeTrue()
        ->and($preferenze->venueDigest)->toBeFalse()
        ->and($preferenze->soldOut)->toBeFalse()
        ->and((string) $user->daily_digest_time)->toStartWith('18:30')
        ->and($user->quiet_hours)->toBe(['from' => '23:00', 'to' => '07:30']);
});

it('scarica dal profilo il file con i propri dati e soltanto quelli', function (): void {
    freezeLocal($this->city, '2026-09-10 18:00');
    $mia = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: ['title' => 'Serata mia nel file']);
    $altrui = occurrenceAtLocal($this->city, $this->category, '2026-09-13 21:00', event: ['title' => 'Serata di qualcun altro']);

    $user = User::factory()->create(['email' => 'esporta.flusso@example.test', 'name' => 'Chi esporta', 'password' => Hash::make('password-esporta')]);
    $altro = User::factory()->create(['email' => 'altro.esporta@example.test']);
    app(SaveOccurrences::class)->one($user, $mia);
    app(SaveOccurrences::class)->one($altro, $altrui);

    $this->get('/il-mio-profilo/dati')->assertRedirect(route('login'));

    /* Dopo l'accesso si torna allo scarico chiesto da ospite. */
    flowAccountWebLogin('esporta.flusso@example.test', 'password-esporta', url('/il-mio-profilo/dati'));

    $this->get('/il-mio-profilo')->assertOk()->assertSee(route('account.profile.export'), false);

    $risposta = $this->get('/il-mio-profilo/dati')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="'.config()->string('app.name').'-dati.json"');

    $dati = json_decode((string) $risposta->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $testo = (string) $risposta->getContent();

    expect($dati['profile']['email'])->toBe('esporta.flusso@example.test')
        ->and($dati['profile']['name'])->toBe('Chi esporta')
        ->and($dati['saved_events'])->toHaveCount(1)
        ->and($dati['saved_events'][0]['event_title'])->toBe('Serata mia nel file')
        ->and($testo)->not->toContain('altro.esporta@example.test')
        ->and($testo)->not->toContain('Serata di qualcun altro')
        ->and($testo)->not->toContain('$2y$');
});

it('cancella l account solo con parola di conferma e password attuale, poi lo stesso indirizzo può registrarsi da capo', function (): void {
    freezeLocal($this->city, '2026-09-10 18:00');
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00');

    $user = User::factory()->create([
        'email' => 'addio.flusso@example.test',
        'name' => 'Chi se ne va',
        'password' => Hash::make('password-di-addio'),
    ]);
    app(SaveOccurrences::class)->one($user, $occurrence);
    Follow::factory()->create(['user_id' => $user->getKey()]);
    $token = $user->createToken('Telefono')->plainTextToken;

    flowAccountWebLogin('addio.flusso@example.test', 'password-di-addio');

    /* La parola giusta con la password sbagliata: chi trova la sessione
       aperta di un altro non basta. */
    $this->from('/il-mio-profilo')->delete('/il-mio-profilo', [
        'conferma' => __('account.profile.delete_keyword'),
        'password' => 'non-e-la-mia',
    ])->assertRedirect('/il-mio-profilo')->assertSessionHasErrors('password');

    $this->from('/il-mio-profilo')->delete('/il-mio-profilo', [
        'conferma' => 'forse',
        'password' => 'password-di-addio',
    ])->assertSessionHasErrors('conferma');

    expect($user->fresh()->trashed())->toBeFalse()
        ->and($user->savedEvents()->count())->toBe(1);
    $this->assertAuthenticatedAs($user);

    $this->delete('/il-mio-profilo', [
        'conferma' => __('account.profile.delete_keyword'),
        'password' => 'password-di-addio',
    ])->assertRedirect(route('home'))->assertSessionHas('status', __('account.profile.deleted'));
    $this->assertGuest();

    $user->refresh();
    expect($user->trashed())->toBeTrue()
        ->and($user->name)->toBeNull()
        ->and($user->email)->toBe('utente-'.$user->getKey().'@anonimo.invalid')
        ->and($user->savedEvents()->count())->toBe(0)
        ->and($user->follows()->count())->toBe(0)
        ->and($user->tokens()->count())->toBe(0);

    /* Le vecchie credenziali non aprono più niente. */
    $this->post('/accedi', ['email' => 'addio.flusso@example.test', 'password' => 'password-di-addio'])
        ->assertSessionHasErrors(['email' => __('account.login.failed')]);
    $this->assertGuest();

    /* L'indirizzo anonimizzato è libero: chi torna si registra come nuovo. */
    $this->post('/registrati', [
        'email' => 'addio.flusso@example.test',
        'password' => 'password-del-ritorno',
        'password_confirmation' => 'password-del-ritorno',
    ])->assertRedirect(route('account.register.sent'));

    $nuovo = User::query()->where('email', 'addio.flusso@example.test')->firstOrFail();
    expect($nuovo->getKey())->not->toBe($user->getKey())
        ->and($nuovo->savedEvents()->count())->toBe(0);
    Notification::assertSentTo($nuovo, RegistrationLink::class);

    /* Il token dell'app è morto con l'account. */
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

it('sceglie l aspetto chiaro dal sito, lo ritrova alla richiesta dopo e nell app, e non accetta temi inventati', function (): void {
    $user = User::factory()->create(['email' => 'aspetto.flusso@example.test', 'password' => Hash::make('password-aspetto')]);

    /* Da ospite la pagina c'è, ma la scelta non si salva. */
    $this->get('/aspetto')->assertOk()->assertSee('data-theme="dark"', false);
    $this->patch('/aspetto', ['appearance' => 'light'])->assertRedirect(route('login'));
    $this->patchJson('/aspetto', ['appearance' => 'light'])->assertUnauthorized();

    flowAccountWebLogin('aspetto.flusso@example.test', 'password-aspetto', url('/aspetto'));

    $this->patch('/aspetto', ['appearance' => 'light'])
        ->assertRedirect(route('appearance'))
        ->assertSessionHas('status');
    expect($user->fresh()->appearance)->toBe('light');

    $this->get('/aspetto')->assertOk()->assertSee('data-theme="light"', false);

    $this->patchJson('/aspetto', ['appearance' => 'system'])->assertUnprocessable();
    expect($user->fresh()->appearance)->toBe('light');

    $this->patchJson('/aspetto', ['appearance' => 'dark'])
        ->assertOk()
        ->assertJsonPath('appearance', 'dark');
    expect($user->fresh()->appearance)->toBe('dark');

    /* Stessa preferenza vista dall'app: l'ultima scelta del sito. */
    $this->withToken($user->createToken('Telefono')->plainTextToken)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.appearance', 'dark');
});
