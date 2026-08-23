<?php

declare(strict_types=1);

use App\Models\SavedEvent;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * **Scenario H di §18.** Un anonimo salva 3 eventi, si registra, ritrova
 * esattamente quei 3 eventi: senza duplicati e senza quelli già passati.
 *
 * È la prova della regola di prodotto più importante di §15: il cuore funziona
 * al primo click, senza registrazione, e ciò che si è salvato da anonimi non
 * si perde quando l'account nasce. La migrazione è la stessa azione sul sito e
 * sull'API — cambia solo chi la chiama.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();

    $this->city = testCity();
    $this->category = testCategory();

    freezeLocal($this->city, '2026-09-10 18:00');

    /* Le tre serate salvate dall'anonimo, tutte future. */
    $this->salvate = [
        occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00'),
        occurrenceAt($this->city, $this->category, '2026-09-18 21:00:00'),
        occurrenceAt($this->city, $this->category, '2026-09-25 21:30:00'),
    ];

    /* Una quarta, salvata settimane fa e ormai passata: non deve rientrare. */
    $this->passata = occurrenceAt($this->city, $this->category, '2026-08-20 21:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('ritrova sul sito esattamente le tre date salvate da anonimo, senza duplicati e senza quelle passate', function (): void {
    /*
     * Ciò che il browser ha nel `localStorage`: le tre serate — una ripetuta,
     * perché due schede aperte possono salvarla due volte — più quella
     * passata e un identificativo che non esiste più.
     */
    $localStorage = [
        (int) $this->salvate[0]->getKey(),
        (int) $this->salvate[1]->getKey(),
        (int) $this->salvate[1]->getKey(),
        (int) $this->salvate[2]->getKey(),
        (int) $this->passata->getKey(),
        999999,
    ];

    $this->post('/registrati', [
        'email' => 'anonima@example.test',
        'password' => 'una-password-molto-lunga',
        'password_confirmation' => 'una-password-molto-lunga',
    ])->assertRedirect(route('account.feed'));

    $user = User::query()->where('email', 'anonima@example.test')->firstOrFail();

    /*
     * La migrazione la chiede il browser subito dopo l'accesso: è la chiamata
     * che lo script fa trovando qualcosa nel `localStorage`.
     */
    $response = $this->actingAs($user)
        ->postJson('/salvataggi/unisci', ['occurrence_ids' => $localStorage])
        ->assertOk();

    /*
     * Il numero nella risposta è ciò che autorizza il browser a svuotare il
     * `localStorage`: senza, si svuoterebbe prima di sapere se il server ha
     * ricevuto (§15.1).
     */
    expect($response->json('merged'))->toBe(3)
        ->and($response->json('ignored'))->toBe(2);

    expect(SavedEvent::query()->where('user_id', $user->getKey())->count())->toBe(3)
        ->and($user->savedEvents()->pluck('occurrence_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all())
        ->toBe(collect($this->salvate)->map(fn ($occurrence): int => (int) $occurrence->getKey())->sort()->values()->all());

    /* La data passata non è entrata. */
    expect($user->savedEvents()->where('occurrence_id', $this->passata->getKey())->exists())->toBeFalse();
});

it('ritrova le stesse tre date passando dall API', function (): void {
    $token = $this->postJson('/api/v1/auth/register', [
        'email' => 'anonima-app@example.test',
        'password' => 'una-password-molto-lunga',
        'device_name' => 'Telefono',
    ])->assertCreated()->json('data.token');

    $response = $this->withToken($token)
        ->postJson('/api/v1/me/saved/merge', [
            'occurrence_ids' => [
                (int) $this->salvate[0]->getKey(),
                (int) $this->salvate[1]->getKey(),
                (int) $this->salvate[1]->getKey(),
                (int) $this->salvate[2]->getKey(),
                (int) $this->passata->getKey(),
            ],
        ])
        ->assertOk();

    expect($response->json('data.merged'))->toBe(3)
        ->and($response->json('data.ignored'))->toBe(1);

    /* E la lista dei salvataggi restituisce le tre serate, nell'ordine in cui
       arrivano: la finestra e l'ordine li decide il motore, non l'endpoint. */
    $lista = $this->withToken($token)->getJson('/api/v1/me/saved')->assertOk();

    expect($lista->json('data.*.occurrence_id'))->toBe([
        (int) $this->salvate[0]->getKey(),
        (int) $this->salvate[1]->getKey(),
        (int) $this->salvate[2]->getKey(),
    ])
        /* Chi è autenticato vede `is_saved`, e qui vale sempre true (§15.8). */
        ->and($lista->json('data.*.is_saved'))->toBe([true, true, true]);
});

it('non duplica nulla se la migrazione viene chiesta due volte', function (): void {
    $user = User::factory()->create();

    $ids = collect($this->salvate)->map(fn ($occurrence): int => (int) $occurrence->getKey())->all();

    $this->actingAs($user)->postJson('/salvataggi/unisci', ['occurrence_ids' => $ids])->assertOk();

    /*
     * La seconda chiamata è quella di uno script che ritenta dopo un errore di
     * rete: il vincolo unico `(user_id, occurrence_id)` la rende innocua.
     */
    $seconda = $this->actingAs($user)->postJson('/salvataggi/unisci', ['occurrence_ids' => $ids])->assertOk();

    expect($seconda->json('merged'))->toBe(3)
        ->and(SavedEvent::query()->where('user_id', $user->getKey())->count())->toBe(3);
});

it('mostra il cuore a chi non è collegato, senza chiedergli di registrarsi', function (): void {
    $response = $this->get(route('events.show', $this->salvate[0]->event))->assertOk();

    /* Il cuore c'è, dichiara la data e dice allo script che nessuno è
       collegato: da lì in poi il salvataggio avviene nel browser. */
    $response->assertSee('data-save-id="'.$this->salvate[0]->getKey().'"', false)
        ->assertSee('data-save-authenticated="0"', false)
        /* E il riquadro del terzo salvataggio è nel documento, nascosto:
           lo rivela lo script quando le date salvate diventano tre. */
        ->assertSee('data-save-prompt', false)
        ->assertSee('data-account-prompt-after="3"', false);
});
