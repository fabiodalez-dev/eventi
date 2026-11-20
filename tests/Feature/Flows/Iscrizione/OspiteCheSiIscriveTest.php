<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\SavedEvent;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Notifications\RegistrationLink;
use App\Notifications\VerifyEmailLink;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * Chi salva da ospite e poi si iscrive o entra (§15.1, D29): le date salvate
 * nel browser si vedono prima dell'account, arrivano nell'account solo quando
 * il server può riceverle, e la risposta della migrazione dice al browser
 * quante sono entrate — è quel numero che autorizza a svuotare il browser.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
    config(['community.enabled' => false]);

    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-11-20 18:00');

    $this->prima = occurrenceAt($this->city, $this->category, '2026-11-22 19:00:00', event: ['title' => 'Jazz al chiostro']);
    $this->seconda = occurrenceAt($this->city, $this->category, '2026-11-27 20:30:00', event: ['title' => 'Teatro di quartiere']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Le date nel `localStorage` di questo browser, come le manda lo script. */
function iscrizioneDateDelBrowser(): array
{
    return [(int) test()->prima->id, (int) test()->seconda->id];
}

it('rivede da ospite le date salvate, si iscrive, conferma e le ritrova nel proprio account', function (): void {
    $ids = iscrizioneDateDelBrowser();

    $this->get('/salvataggi/pannello?ids='.implode(',', $ids))
        ->assertOk()->assertSee('Jazz al chiostro')->assertSee('Teatro di quartiere');

    $this->post('/registrati', [
        'email' => 'salvava.da.ospite@example.test',
        'password' => 'password-di-chi-salvava',
        'password_confirmation' => 'password-di-chi-salvava',
    ])->assertRedirect(route('account.register.sent'));

    $user = User::query()->where('email', 'salvava.da.ospite@example.test')->firstOrFail();
    $this->get(Notification::sent($user, RegistrationLink::class)->first()->url)->assertRedirect(route('account.feed'));

    $risposta = $this->postJson('/salvataggi/unisci', ['occurrence_ids' => $ids])->assertOk();
    expect($risposta->json('merged'))->toBe(2)
        ->and($risposta->json('ignored'))->toBe(0)
        ->and($risposta->json('message'))->toBe(trans_choice('account.save.merged', 2, ['count' => 2]));

    $this->get('/i-miei-salvataggi')->assertOk()->assertSee('Jazz al chiostro')->assertSee('Teatro di quartiere');
    /* Ora il pannello legge l'account, non l'indirizzo. */
    $this->get('/salvataggi/pannello')->assertOk()->assertSee('Jazz al chiostro');
});

it('lascia le date nel browser finché l account non è confermato, e le unisce dopo la conferma', function (): void {
    $user = User::factory()->unverified()->create([
        'email' => 'vecchio.non.confermato@example.test',
        'password' => Hash::make('password-vecchio-account'),
    ]);

    $this->post('/accedi', ['email' => 'vecchio.non.confermato@example.test', 'password' => 'password-vecchio-account'])->assertRedirect();

    /* Lo script prova a unire: il server non può ancora, e lo dice in JSON. */
    $this->postJson('/salvataggi/unisci', ['occurrence_ids' => iscrizioneDateDelBrowser()])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED')
        ->assertJsonMissingPath('merged');
    expect(SavedEvent::query()->where('user_id', $user->id)->count())->toBe(0);

    $this->post('/email/verifica')->assertRedirect();
    $this->get((string) Notification::sent($user, VerifyEmailLink::class)->first()->toMail($user)->actionUrl)->assertRedirect();

    $this->postJson('/salvataggi/unisci', ['occurrence_ids' => iscrizioneDateDelBrowser()])
        ->assertOk()->assertJsonPath('merged', 2);
    expect(SavedEvent::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('unisce senza doppioni le date del browser a quelle che l account aveva già', function (): void {
    $user = User::factory()->create(['email' => 'aveva.gia.salvato@example.test']);
    $terza = occurrenceAt($this->city, $this->category, '2026-12-03 21:00:00', event: ['title' => 'Cinema all aperto']);
    SavedEvent::query()->create(['user_id' => $user->id, 'occurrence_id' => $this->prima->id]);
    SavedEvent::query()->create(['user_id' => $user->id, 'occurrence_id' => $terza->id]);

    /* Entra con il collegamento senza password, dallo stesso browser. */
    $this->post('/accedi/collegamento', ['email' => 'aveva.gia.salvato@example.test'])->assertRedirect();
    $this->get((string) Notification::sent($user, MagicLoginLink::class)->first()->toMail($user)->actionUrl)->assertRedirect(route('account.feed'));

    $risposta = $this->postJson('/salvataggi/unisci', ['occurrence_ids' => iscrizioneDateDelBrowser()])->assertOk();

    /* Quella già salvata conta fra le unite: il browser può svuotarsi. */
    expect($risposta->json('merged'))->toBe(2)
        ->and(SavedEvent::query()->where('user_id', $user->id)->pluck('occurrence_id')->map(fn ($id): int => (int) $id)->sort()->values()->all())
        ->toBe(collect([$this->prima->id, $this->seconda->id, $terza->id])->map(fn ($id): int => (int) $id)->sort()->values()->all());
});

it('respinge un elenco oltre il tetto senza salvare niente, e accetta lo stesso diviso in parti', function (): void {
    config(['account.merge_max' => 1]);
    $user = User::factory()->create(['email' => 'tante.date@example.test', 'password' => Hash::make('password-tante-date')]);

    $this->post('/accedi', ['email' => 'tante.date@example.test', 'password' => 'password-tante-date'])->assertRedirect();

    $this->postJson('/salvataggi/unisci', ['occurrence_ids' => iscrizioneDateDelBrowser()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('occurrence_ids');
    expect(SavedEvent::query()->where('user_id', $user->id)->count())->toBe(0);

    foreach (iscrizioneDateDelBrowser() as $id) {
        $this->postJson('/salvataggi/unisci', ['occurrence_ids' => [$id]])->assertOk()->assertJsonPath('merged', 1);
    }
    expect(SavedEvent::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('dall app unisce solo le date che il pubblico può vedere e lascia fuori bozze e date finite', function (): void {
    $user = User::factory()->create(['email' => 'unione.app@example.test', 'password' => Hash::make('password-unione-app')]);
    $bozza = occurrenceAt($this->city, $this->category, '2026-11-29 21:00:00', event: ['status' => EventStatus::Draft, 'published_at' => null]);
    $finita = occurrenceAt($this->city, $this->category, '2026-11-01 21:00:00');

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'unione.app@example.test', 'password' => 'password-unione-app', 'device_name' => 'Telefono',
    ])->assertOk()->json('data.token');

    $risposta = $this->withToken($token)->postJson('/api/v1/me/saved/merge', [
        'occurrence_ids' => [...iscrizioneDateDelBrowser(), (int) $bozza->id, (int) $finita->id, 999999],
    ])->assertOk();

    expect($risposta->json('data.merged'))->toBe(2)
        ->and($risposta->json('data.ignored'))->toBe(3)
        ->and(SavedEvent::query()->where('user_id', $user->id)->whereIn('occurrence_id', [$bozza->id, $finita->id])->exists())->toBeFalse();

    $this->withToken($token)->getJson('/api/v1/me/saved')->assertOk()
        ->assertJsonPath('data.*.occurrence_id', iscrizioneDateDelBrowser());
});
