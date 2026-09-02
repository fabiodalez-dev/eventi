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

it('mostra il cuore a chi non è collegato, senza chiedergli di registrarsi prima', function (): void {
    $response = $this->get(route('events.show', $this->salvate[0]->event))->assertOk();

    /* Il cuore c'è, dichiara la data e dice allo script che nessuno è
       collegato: da lì in poi il salvataggio avviene nel browser. */
    $response->assertSee('data-save-id="'.$this->salvate[0]->getKey().'"', false)
        ->assertSee('data-save-authenticated="0"', false)
        /* L'invito è nel documento ma **chiuso**: un `<dialog>` senza `open`
           non si vede e non intercetta niente. Lo apre lo script dopo il primo
           salvataggio — a salvataggio già scritto, mai prima (D49). */
        ->assertSee('data-save-prompt', false)
        ->assertSee('data-account-prompt-after="1"', false)
        ->assertDontSee('<dialog data-save-prompt open', false);
});

it('lascia il cuore funzionante anche senza JavaScript', function (): void {
    /*
     * Il dialogo è una cortesia dello script, non il meccanismo. Sotto c'è un
     * modulo vero verso una rotta protetta da `auth`: chi non è collegato e
     * non ha JavaScript finisce alla pagina di accesso invece di premere un
     * pulsante che non fa niente.
     */
    $this->post(route('account.saved.store'), ['occurrence_id' => $this->salvate[0]->getKey()])
        ->assertRedirect(route('login'));
});

/*
 * I tre vincoli che rendono sopportabile un dialogo sul primo gesto (D49).
 * Sono la ragione per cui questa scelta non ricade nel «chiedere l'email prima
 * di poter salvare» contro cui §15.1 metteva in guardia: se saltano, salta la
 * ragione.
 */
it('tiene l invito chiuso finché nessuno tocca il cuore', function (): void {
    /*
     * Un `<dialog>` senza `open` non si vede e non intercetta niente. Lo apre
     * lo script, e solo come conseguenza di un gesto: mai al caricamento.
     */
    $this->get(route('events.show', $this->salvate[0]->event))
        ->assertOk()
        ->assertSee('data-save-prompt', false)
        ->assertDontSee('data-save-prompt open', false);
});

it('non chiama l invito al caricamento della pagina', function (): void {
    $script = (string) file_get_contents(base_path('resources/js/app.js'));

    /*
     * `start()` gira a ogni caricamento. Se `showPromptIfDue()` finisse lì
     * dentro, il dialogo si aprirebbe da solo a chi non ha toccato niente —
     * la cosa più invadente che si possa fare, e per giunta su ogni pagina.
     */
    $inizio = mb_strpos($script, 'function start()');

    expect($inizio)->not->toBeFalse()
        ->and(mb_substr($script, $inizio, 900))
        ->not->toContain('showPromptIfDue();');
});

it('ricorda il rifiuto comunque lo si chiuda, non solo col pulsante', function (): void {
    $script = (string) file_get_contents(base_path('resources/js/app.js'));

    /*
     * `Esc` e il click sullo sfondo non passano dal gestore del pulsante: se
     * la memoria si scrivesse lì, chi chiude in quei modi si vedrebbe
     * riproporre il dialogo al salvataggio successivo. Va sull'evento `close`
     * del dialogo, che è l'unico punto che li raccoglie tutti.
     */
    /* Le virgolette le decide Prettier, non noi: si accettano entrambe. */
    expect($script)->toMatch('/prompt\.addEventListener\([\'"]close[\'"]/');
});

it('offre anche l accesso, non solo la registrazione', function (): void {
    /*
     * Chi un account ce l'ha gia' non deve trovarsi davanti un invito a
     * crearne un altro: e' il caso di chi ha salvato da sloggato, che e'
     * proprio quello in cui il travaso serve di piu'.
     */
    $this->get(route('events.show', $this->salvate[0]->event))
        ->assertOk()
        ->assertSee(route('account.register'), false)
        ->assertSee(route('login'), false)
        ->assertSee(__('account.prompt.login'), false);
});

it('chiude l invito anche quando si preme una delle sue azioni', function (): void {
    $script = (string) file_get_contents(base_path('resources/js/app.js'));

    /*
     * «Crea un account» e «ho gia un account» sono collegamenti: portano via
     * dalla pagina, ma senza `close()` il dialogo non viene mai chiuso e
     * l'evento che registra la risposta non scatta. Chi ci ripensa e torna
     * indietro se lo ritrova davanti — ed e' il difetto che si vedeva in
     * produzione con la versione precedente.
     *
     * `close()` non annulla il click: il collegamento parte lo stesso.
     */
    $inizio = mb_strpos($script, 'function dismissPrompt()');

    expect($inizio)->not->toBeFalse()
        ->and(mb_substr($script, $inizio, 1600))
        ->toContain('querySelectorAll("a[href]")');
});
