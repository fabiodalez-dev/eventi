<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\SavedEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * Il profilo dopo l'iscrizione (§15.2, §15.9): i dati che si possono
 * cambiare e quelli che no, le preferenze che il sito scrive e l'app rilegge,
 * il tema scelto da ospite che segue l'account, e la cancellazione con la sua
 * prova e il suo limite.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->city = testCity();
    Notification::fake();
    config(['community.enabled' => false]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function iscrizioneProfilo(string $email, array $attributi = []): User
{
    return User::factory()->create(['email' => $email, 'password' => Hash::make('password-del-profilo'), ...$attributi]);
}

/** I campi che il modulo del profilo manda sempre. */
function iscrizioneModuloProfilo(array $campi = []): array
{
    return ['name' => 'Nome Qualunque', 'timezone' => 'Europe/Rome', 'locale' => 'it', ...$campi];
}

it('non cambia l indirizzo email dal modulo del profilo, nemmeno se il campo arriva', function (): void {
    $user = iscrizioneProfilo('indirizzo.fisso@example.test');
    $this->actingAs($user);

    $this->get('/il-mio-profilo')->assertOk()->assertSee('indirizzo.fisso@example.test');

    $this->patch('/il-mio-profilo', iscrizioneModuloProfilo([
        'name' => 'Nome Cambiato',
        'email' => 'indirizzo.nuovo@example.test',
        'profile_only' => '1',
    ]))->assertRedirect(route('account.profile'))->assertSessionHas('status', __('account.profile.saved'));

    $user->refresh();
    expect($user->name)->toBe('Nome Cambiato')
        ->and($user->email)->toBe('indirizzo.fisso@example.test')
        ->and($user->hasVerifiedEmail())->toBeTrue();

    /* Né dall'app. */
    $token = $user->createToken('Telefono')->plainTextToken;
    $this->withToken($token)->patchJson('/api/v1/me', ['email' => 'dall.app@example.test', 'name' => 'Dall App'])->assertOk()
        ->assertJsonPath('data.email', 'indirizzo.fisso@example.test');
    expect($user->fresh()->email)->toBe('indirizzo.fisso@example.test');
});

it('respinge un nome troppo lungo e una lingua che non esiste senza toccare il profilo', function (): void {
    $user = iscrizioneProfilo('profilo.intatto@example.test', ['name' => 'Nome Originale', 'locale' => 'it', 'timezone' => 'Europe/Rome']);
    $this->actingAs($user);

    $this->from('/il-mio-profilo')->patch('/il-mio-profilo', iscrizioneModuloProfilo(['name' => str_repeat('a', 256), 'profile_only' => '1']))
        ->assertRedirect('/il-mio-profilo')->assertSessionHasErrors('name');
    $this->from('/il-mio-profilo')->patch('/il-mio-profilo', iscrizioneModuloProfilo(['locale' => 'kl', 'profile_only' => '1']))
        ->assertSessionHasErrors('locale');
    $this->from('/il-mio-profilo')->patch('/il-mio-profilo', iscrizioneModuloProfilo(['timezone' => '', 'profile_only' => '1']))
        ->assertSessionHasErrors('timezone');

    $user->refresh();
    expect($user->name)->toBe('Nome Originale')
        ->and($user->locale)->toBe('it')
        ->and($user->timezone)->toBe('Europe/Rome');

    /* Il nome vuoto è un nome tolto, non un errore. */
    $this->patch('/il-mio-profilo', iscrizioneModuloProfilo(['name' => '', 'profile_only' => '1']))->assertSessionHasNoErrors();
    expect($user->fresh()->name)->toBeNull();
});

it('salvando solo i dati personali lascia com erano le notifiche e il consenso alla newsletter', function (): void {
    config(['pennant.defaults.newsletter' => true]);
    $user = iscrizioneProfilo('solo.dati@example.test');
    $this->actingAs($user);

    /* Prima sceglie le notifiche: spegne i promemoria, tiene il riepilogo, dà il consenso. */
    $this->patch('/il-mio-profilo', iscrizioneModuloProfilo([
        'reminders' => '0',
        'daily_digest' => '1',
        'daily_digest_time' => '08:30',
        'marketing_opt_in' => '1',
    ]))->assertSessionHasNoErrors();
    $user->refresh();
    $preferenze = $user->notification_preferences;
    $consenso = $user->marketing_opt_in_at;
    expect($consenso)->not->toBeNull();

    /* Poi cambia solo il nome dal riquadro dei dati. */
    CarbonImmutable::setTestNow(now()->addDay());
    $this->patch('/il-mio-profilo', iscrizioneModuloProfilo(['name' => 'Solo Il Nome', 'profile_only' => '1']))->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->name)->toBe('Solo Il Nome')
        ->and($user->notification_preferences)->toBe($preferenze)
        ->and($user->daily_digest_time)->toStartWith('08:30')
        ->and($user->marketing_opt_in_at?->equalTo($consenso))->toBeTrue();
});

it('tiene la data del consenso alla newsletter quando lo si riconferma e la toglie quando lo si revoca', function (): void {
    config(['pennant.defaults.newsletter' => true]);
    $user = iscrizioneProfilo('consenso.datato@example.test');
    $this->actingAs($user);

    $this->patch('/il-mio-profilo', iscrizioneModuloProfilo(['marketing_opt_in' => '1']))->assertSessionHasNoErrors();
    $dato = $user->fresh()->marketing_opt_in_at;

    CarbonImmutable::setTestNow(now()->addWeeks(2));
    $this->patch('/il-mio-profilo', iscrizioneModuloProfilo(['marketing_opt_in' => '1', 'name' => 'Altro Nome']))->assertSessionHasNoErrors();
    expect($user->fresh()->marketing_opt_in_at?->equalTo($dato))->toBeTrue();

    $this->patch('/il-mio-profilo', iscrizioneModuloProfilo())->assertSessionHasNoErrors();
    expect($user->fresh()->marketing_opt_in_at)->toBeNull();

    /* L'app legge lo stesso stato. */
    $token = $user->createToken('Telefono')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/me/notification-preferences')->assertOk()
        ->assertJsonPath('data.marketing_opt_in', false);
});

it('sceglie dal sito le categorie che interessano e quelle da nascondere, e l app le ritrova', function (): void {
    $user = iscrizioneProfilo('interessi.scelti@example.test');
    $musica = testCategory(['name' => 'Concerti']);
    $teatro = Category::factory()->create(['name' => 'Teatro', 'is_active' => true]);
    $this->actingAs($user);

    $this->get('/profilo/interessi')->assertOk()->assertSee('Concerti')->assertSee('Teatro');

    $this->patch('/profilo/interessi', [
        'mode' => 'selected',
        'choices' => [$musica->id => 'interested', $teatro->id => 'hidden'],
    ])->assertRedirect(route('account.content-preferences'));

    /* La stessa categoria non può essere scelta e nascosta insieme. */
    $this->patchJson('/api/v1/me/content-preferences', [
        'mode' => 'selected', 'categories' => [$musica->id], 'hidden_categories' => [$musica->id], 'inferred_ads' => false,
    ], ['Authorization' => 'Bearer '.$user->createToken('Telefono')->plainTextToken])->assertUnprocessable();

    $this->getJson('/api/v1/me/content-preferences', ['Authorization' => 'Bearer '.$user->createToken('Tablet')->plainTextToken])
        ->assertOk()
        ->assertJsonPath('data.selection.mode', 'selected')
        ->assertJsonPath('data.selection.categories', [$musica->id])
        ->assertJsonPath('data.selection.hidden_categories', [$teatro->id]);
});

it('adotta all accesso il tema scelto da ospite e poi lascia tornare a quello del sistema', function (): void {
    $user = iscrizioneProfilo('tema.da.ospite@example.test', ['appearance' => null]);

    /* Da ospite il tema sta in un cookie del browser. */
    $this->withUnencryptedCookie('incitta_appearance', 'dark')
        ->post('/accedi', ['email' => 'tema.da.ospite@example.test', 'password' => 'password-del-profilo'])
        ->assertRedirect(route('account.feed'));
    expect($user->fresh()->appearance)->toBe('dark');

    $this->patchJson('/aspetto', ['appearance' => 'system'])->assertOk()->assertJsonPath('appearance', null);
    expect($user->fresh()->appearance)->toBeNull();

    /* Da un altro browser con il tema chiaro, chi segue il sistema prende quello del browser. */
    $this->post('/esci');
    app('auth')->forgetGuards();
    $this->withUnencryptedCookie('incitta_appearance', 'light')
        ->post('/accedi', ['email' => 'tema.da.ospite@example.test', 'password' => 'password-del-profilo'])
        ->assertRedirect();
    expect($user->fresh()->appearance)->toBe('light');

    /* Una scelta esplicita invece resta, qualunque cookie abbia il browser. */
    $this->post('/esci');
    $this->withUnencryptedCookie('incitta_appearance', 'dark')
        ->post('/accedi', ['email' => 'tema.da.ospite@example.test', 'password' => 'password-del-profilo'])
        ->assertRedirect();
    expect($user->fresh()->appearance)->toBe('light');

    /* Tornata su «sistema», l'app — che vuole sempre una stringa — legge la scelta a parte. */
    $this->patchJson('/aspetto', ['appearance' => 'system'])->assertOk();
    $this->withToken($user->createToken('Telefono')->plainTextToken)->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.appearance_choice', null)
        ->assertJsonPath('data.appearance', fn (mixed $valore): bool => is_string($valore));
});

it('ferma chi tira a indovinare la password della cancellazione, e dopo l attesa lascia cancellare al titolare', function (): void {
    $user = iscrizioneProfilo('cancella.con.calma@example.test');
    $this->actingAs($user);

    foreach (range(1, 5) as $volta) {
        $this->from('/il-mio-profilo')->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword'), 'password' => 'tentativo-'.$volta])
            ->assertSessionHasErrors('password');
    }

    $this->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword'), 'password' => 'password-del-profilo'])
        ->assertStatus(429);
    expect($user->fresh()?->trashed())->toBeFalse();

    $this->travel(16)->minutes();
    $this->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword'), 'password' => 'password-del-profilo'])
        ->assertRedirect(route('home'))
        ->assertSessionHas('status', __('account.profile.deleted'));
    $this->assertGuest();
    expect(User::withTrashed()->findOrFail($user->id)->trashed())->toBeTrue();
});

it('lascia scaricare i dati e cancellare l account anche a chi non ha mai confermato l indirizzo', function (): void {
    $user = iscrizioneProfilo('mai.confermato@example.test', ['email_verified_at' => null]);
    $occurrence = occurrenceAt($this->city, testCategory(), '2026-11-28 20:00:00');
    SavedEvent::query()->create(['user_id' => $user->id, 'occurrence_id' => $occurrence->id]);
    $token = $user->createToken('Telefono')->plainTextToken;

    $this->post('/accedi', ['email' => 'mai.confermato@example.test', 'password' => 'password-del-profilo'])->assertRedirect();
    $this->get('/i-miei-salvataggi')->assertRedirect(route('verification.notice'));

    $this->get('/il-mio-profilo/dati')->assertOk()
        ->assertHeader('Content-Disposition')
        ->assertJsonPath('profile.email', 'mai.confermato@example.test');

    $this->delete('/il-mio-profilo', ['conferma' => __('account.profile.delete_keyword'), 'password' => 'password-del-profilo'])
        ->assertRedirect(route('home'));

    $cancellato = User::withTrashed()->findOrFail($user->id);
    expect($cancellato->trashed())->toBeTrue()
        ->and($cancellato->email)->not->toBe('mai.confermato@example.test')
        ->and(SavedEvent::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and($cancellato->tokens()->count())->toBe(0);

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});
