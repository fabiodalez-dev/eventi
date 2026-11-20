<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * Il recupero della password dall'inizio alla fine (§15.2): la richiesta, il
 * messaggio, la pagina della nuova password e l'accesso dopo. Protegge i casi
 * che una persona incontra davvero: l'indirizzo scritto in un altro modo, il
 * collegamento chiesto due volte o aperto tardi, la password nuova troppo
 * debole, il vecchio collegamento dei pannelli.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    Notification::fake();
    config(['community.enabled' => false, 'api.password_reset_url' => null]);
});

/** Token e indirizzo dell'ultimo collegamento di reimpostazione spedito. */
function iscrizioneReset(User $user): array
{
    $notifica = Notification::sent($user, ResetPasswordLink::class)->last();

    expect($notifica)->toBeInstanceOf(ResetPasswordLink::class);

    $url = (string) $notifica->toMail($user)->actionUrl;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return ['url' => $url, 'token' => (string) $query['token'], 'email' => (string) $query['email']];
}

function iscrizioneSmemorato(string $email, string $password = 'la-password-dimenticata'): User
{
    return User::factory()->create(['email' => $email, 'password' => Hash::make($password)]);
}

it('recupera la password chiedendola con l indirizzo in maiuscolo e poi entra con quella nuova', function (): void {
    $user = iscrizioneSmemorato('lucia.maiuscole@example.test');

    $this->post('/password-dimenticata', ['email' => 'LUCIA.Maiuscole@Example.test'])
        ->assertRedirect(route('account.password.request'))
        ->assertSessionHas('status', __('account.forgot.sent'));
    Notification::assertSentToTimes($user, ResetPasswordLink::class, 1);

    $reset = iscrizioneReset($user);
    /* Il collegamento porta alla pagina del sito, che esiste e mostra l'indirizzo. */
    expect($reset['url'])->toStartWith(route('account.password.reset'));
    $this->get($reset['url'])->assertOk()->assertSee('lucia.maiuscole@example.test');

    $this->post('/reimposta-password', [
        'token' => $reset['token'],
        'email' => $reset['email'],
        'password' => 'la-password-ritrovata',
        'password_confirmation' => 'la-password-ritrovata',
    ])->assertRedirect(route('login'));

    $this->post('/accedi', ['email' => 'lucia.maiuscole@example.test', 'password' => 'la-password-dimenticata'])
        ->assertSessionHasErrors('email');
    $this->post('/accedi', ['email' => 'lucia.maiuscole@example.test', 'password' => 'la-password-ritrovata'])
        ->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);
});

it('chiesto due volte di fila manda un solo messaggio, e il secondo chiesto più tardi rende inutile il primo', function (): void {
    $user = iscrizioneSmemorato('chiede.due.volte@example.test');

    $this->post('/password-dimenticata', ['email' => 'chiede.due.volte@example.test'])->assertSessionHas('status', __('account.forgot.sent'));
    $this->post('/password-dimenticata', ['email' => 'chiede.due.volte@example.test'])->assertSessionHas('status', __('account.forgot.sent'));
    Notification::assertSentToTimes($user, ResetPasswordLink::class, 1);
    $primo = iscrizioneReset($user);

    $this->travel(2)->minutes();
    $this->post('/password-dimenticata', ['email' => 'chiede.due.volte@example.test'])->assertSessionHas('status', __('account.forgot.sent'));
    Notification::assertSentToTimes($user, ResetPasswordLink::class, 2);
    $secondo = iscrizioneReset($user);
    expect($secondo['token'])->not->toBe($primo['token']);

    $this->from($primo['url'])->post('/reimposta-password', [
        'token' => $primo['token'],
        'email' => $primo['email'],
        'password' => 'dal-primo-messaggio',
        'password_confirmation' => 'dal-primo-messaggio',
    ])->assertSessionHasErrors(['email' => __('account.reset.failed')]);
    expect(Hash::check('la-password-dimenticata', (string) $user->fresh()->password))->toBeTrue();

    $this->post('/reimposta-password', [
        'token' => $secondo['token'],
        'email' => $secondo['email'],
        'password' => 'dal-secondo-messaggio',
        'password_confirmation' => 'dal-secondo-messaggio',
    ])->assertRedirect(route('login'));
    expect(Hash::check('dal-secondo-messaggio', (string) $user->fresh()->password))->toBeTrue();
});

it('rifiuta il collegamento aperto dopo un ora e lascia chiederne uno nuovo', function (): void {
    $user = iscrizioneSmemorato('apre.tardi@example.test');

    $this->post('/password-dimenticata', ['email' => 'apre.tardi@example.test']);
    $scaduto = iscrizioneReset($user);

    $this->travel(config()->integer('auth.passwords.users.expire') + 1)->minutes();

    $this->from($scaduto['url'])->post('/reimposta-password', [
        'token' => $scaduto['token'],
        'email' => $scaduto['email'],
        'password' => 'troppo-tardi-ormai',
        'password_confirmation' => 'troppo-tardi-ormai',
    ])->assertRedirect($scaduto['url'])
        ->assertSessionHasErrors(['email' => __('account.reset.failed')])
        ->assertSessionHasInput('email', 'apre.tardi@example.test');
    expect(Hash::check('la-password-dimenticata', (string) $user->fresh()->password))->toBeTrue();

    $this->post('/password-dimenticata', ['email' => 'apre.tardi@example.test']);
    $nuovo = iscrizioneReset($user);
    $this->post('/reimposta-password', [
        'token' => $nuovo['token'],
        'email' => $nuovo['email'],
        'password' => 'questa-volta-in-tempo',
        'password_confirmation' => 'questa-volta-in-tempo',
    ])->assertRedirect(route('login'))->assertSessionHas('status', __('account.reset.done'));
});

it('non lascia usare il gettone di una persona per cambiare la password di un altra', function (): void {
    $mia = iscrizioneSmemorato('mia.casella@example.test', 'password-mia-originale');
    $altrui = iscrizioneSmemorato('casella.altrui@example.test', 'password-altrui-originale');

    $this->post('/password-dimenticata', ['email' => 'mia.casella@example.test']);
    $reset = iscrizioneReset($mia);

    $this->from($reset['url'])->post('/reimposta-password', [
        'token' => $reset['token'],
        'email' => 'casella.altrui@example.test',
        'password' => 'password-presa-in-prestito',
        'password_confirmation' => 'password-presa-in-prestito',
    ])->assertSessionHasErrors(['email' => __('account.reset.failed')]);

    expect(Hash::check('password-altrui-originale', (string) $altrui->fresh()->password))->toBeTrue()
        ->and(Hash::check('password-mia-originale', (string) $mia->fresh()->password))->toBeTrue();

    /* Il gettone resta buono per la casella a cui è stato mandato. */
    $this->post('/reimposta-password', [
        'token' => $reset['token'],
        'email' => $reset['email'],
        'password' => 'password-mia-nuova',
        'password_confirmation' => 'password-mia-nuova',
    ])->assertRedirect(route('login'));
    expect(Hash::check('password-mia-nuova', (string) $mia->fresh()->password))->toBeTrue();
});

it('respinge una password nuova troppo corta senza bruciare il collegamento', function (): void {
    $user = iscrizioneSmemorato('prima.debole@example.test');

    $this->post('/password-dimenticata', ['email' => 'prima.debole@example.test']);
    $reset = iscrizioneReset($user);

    $this->from($reset['url'])->post('/reimposta-password', [
        'token' => $reset['token'],
        'email' => $reset['email'],
        'password' => 'corta',
        'password_confirmation' => 'corta',
    ])->assertRedirect($reset['url'])->assertSessionHasErrors('password');

    $this->post('/reimposta-password', [
        'token' => $reset['token'],
        'email' => $reset['email'],
        'password' => 'adesso-abbastanza-lunga',
        'password_confirmation' => 'adesso-abbastanza-lunga',
    ])->assertRedirect(route('login'));

    $this->post('/accedi', ['email' => 'prima.debole@example.test', 'password' => 'adesso-abbastanza-lunga'])->assertRedirect(route('account.feed'));
});

it('lascia scegliere da collegati la password che serve a cancellare l account', function (): void {
    /* Una persona che è sempre entrata con il collegamento: la sua password non la conosce nessuno. */
    $user = User::factory()->create(['email' => 'solo.collegamento@example.test']);
    $this->actingAs($user);

    $this->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword'), 'password' => 'non-la-so'])
        ->assertSessionHasErrors('password');

    $this->get('/password-dimenticata')->assertOk();
    $this->post('/password-dimenticata', ['email' => 'solo.collegamento@example.test'])->assertRedirect(route('account.password.request'));
    $reset = iscrizioneReset($user);

    $this->get($reset['url'])->assertOk();
    $this->post('/reimposta-password', [
        'token' => $reset['token'],
        'email' => $reset['email'],
        'password' => 'scelta-per-cancellare',
        'password_confirmation' => 'scelta-per-cancellare',
    ])->assertRedirect(route('login'));

    /* La password è cambiata: la sessione di prima si chiude, come quella
       di chiunque l'avesse. Si rientra con la password nuova. La richiesta
       dopo rilegge l'account dal database, come in produzione. */
    app('auth')->forgetGuards();
    $this->get('/il-mio-profilo')->assertRedirect(route('login'));
    $this->assertGuest();
    /* E torna al profilo da cui era stato mandato all'accesso. */
    $this->post('/accedi', ['email' => 'solo.collegamento@example.test', 'password' => 'scelta-per-cancellare'])
        ->assertRedirect(route('account.profile'));

    /* Con la password nuova la cancellazione passa. */
    $this->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword'), 'password' => 'scelta-per-cancellare'])
        ->assertRedirect(route('home'));
    expect(User::withTrashed()->findOrFail($user->id)->trashed())->toBeTrue();
});

it('apre il vecchio collegamento di recupero del pannello, cambia la password ed entra in redazione', function (): void {
    $redattrice = iscrizioneSmemorato('redattrice@example.test');
    $redattrice->assignRole(UserRole::Admin->value);

    $this->post('/password-dimenticata', ['email' => 'redattrice@example.test']);
    $reset = iscrizioneReset($redattrice);

    /* Il messaggio spedito prima del login unico portava al pannello. */
    $vecchio = url('/admin/password-reset/reset').'?'.http_build_query(['token' => $reset['token'], 'email' => $reset['email']]);
    $pagina = $this->get($vecchio)->assertRedirect();
    $this->get((string) $pagina->headers->get('Location'))->assertOk()->assertSee('redattrice@example.test');

    $this->post('/reimposta-password', [
        'token' => $reset['token'],
        'email' => $reset['email'],
        'password' => 'nuova-password-redazione',
        'password_confirmation' => 'nuova-password-redazione',
    ])->assertRedirect(route('login'));

    $this->get('/admin')->assertRedirect(route('login', ['intended' => url('/admin')]));
    $this->post('/accedi', ['email' => 'redattrice@example.test', 'password' => 'nuova-password-redazione'])
        ->assertRedirect(url('/admin'));
    $this->get('/admin')->assertOk();
});

it('usa nell app il collegamento chiesto dal sito e butta fuori i telefoni già collegati', function (): void {
    $user = iscrizioneSmemorato('sito.e.app@example.test');
    $vecchio = $this->postJson('/api/v1/auth/login', [
        'email' => 'sito.e.app@example.test', 'password' => 'la-password-dimenticata', 'device_name' => 'Telefono rubato',
    ])->assertOk()->json('data.token');

    $this->post('/password-dimenticata', ['email' => 'sito.e.app@example.test']);
    $reset = iscrizioneReset($user);

    $this->postJson('/api/v1/auth/password/reset', [
        'token' => $reset['token'],
        'email' => $reset['email'],
        'password' => 'reimpostata-dall-app',
        'password_confirmation' => 'reimpostata-dall-app',
    ])->assertOk()->assertJsonPath('data.message', __('api.auth.password_reset'));

    app('auth')->forgetGuards();
    $this->withToken($vecchio)->getJson('/api/v1/me')->assertUnauthorized();

    $this->post('/accedi', ['email' => 'sito.e.app@example.test', 'password' => 'reimpostata-dall-app'])->assertRedirect(route('account.feed'));
    $this->assertAuthenticatedAs($user);
});

it('riporta al modulo della nuova password, con gettone e indirizzo, chi sbaglia troppe volte di fila', function (): void {
    $user = iscrizioneSmemorato('troppi.tentativi@example.test');
    $this->post('/password-dimenticata', ['email' => 'troppi.tentativi@example.test']);
    $reset = iscrizioneReset($user);
    /* La richiesta del collegamento conta nello stesso minuto: si parte da capo. */
    $this->travel(61)->seconds();

    foreach (range(1, config()->integer('api.rate_limit.auth')) as $volta) {
        $this->from($reset['url'])->post('/reimposta-password', [
            'token' => 'gettone-inventato-'.$volta,
            'email' => $reset['email'],
            'password' => 'tentativo-numero-'.$volta,
            'password_confirmation' => 'tentativo-numero-'.$volta,
        ])->assertSessionHasErrors('email');
    }

    $this->post('/reimposta-password', [
        'token' => $reset['token'],
        'email' => $reset['email'],
        'password' => 'quella-giusta-lunga',
        'password_confirmation' => 'quella-giusta-lunga',
    ])->assertRedirect(route('account.password.reset', ['token' => $reset['token'], 'email' => $reset['email']]))
        ->assertSessionHasErrors('form');
    expect(Hash::check('la-password-dimenticata', (string) $user->fresh()->password))->toBeTrue();

    /* Passato il minuto, lo stesso collegamento funziona ancora. */
    $this->travel(61)->seconds();
    $this->post('/reimposta-password', [
        'token' => $reset['token'],
        'email' => $reset['email'],
        'password' => 'quella-giusta-lunga',
        'password_confirmation' => 'quella-giusta-lunga',
    ])->assertRedirect(route('login'));
    expect(Hash::check('quella-giusta-lunga', (string) $user->fresh()->password))->toBeTrue();
});
