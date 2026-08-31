<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Models\Device;
use App\Models\Follow;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;

/**
 * §15.8 e §15.9 — portabilità dei dati.
 *
 * Un export è un endpoint che restituisce dati personali a chi li chiede, e
 * l'errore che vale la pena presidiare non è «manca un campo»: è **il campo di
 * troppo**. Un `->get()` senza `where('user_id', ...)` produce un export che
 * funziona, che contiene tutto quello che deve contenere, e che consegna a
 * ciascuno l'archivio di tutti gli altri.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');

    $this->mia = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: ['title' => 'La mia serata']);
    $this->altrui = occurrenceAtLocal($this->city, $this->category, '2026-09-13 21:00', event: ['title' => 'La serata di un altro']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('consegna quello che la persona ha dato: profilo, salvataggi, follow, dispositivi', function (): void {
    $user = User::factory()->create(['email' => 'io@example.test', 'name' => 'Io']);
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    app(SaveOccurrences::class)->one($user, $this->mia);
    Follow::factory()->create([
        'user_id' => $user->getKey(),
        'followable_type' => $venue->getMorphClass(),
        'followable_id' => $venue->getKey(),
    ]);
    Device::factory()->create(['user_id' => $user->getKey()]);
    NotificationLog::factory()->create(['user_id' => $user->getKey()]);

    $export = $this->withToken($user->createToken('Telefono')->plainTextToken)
        ->getJson('/api/v1/me/export')
        ->assertOk();

    expect($export->json('data.profile.email'))->toBe('io@example.test')
        ->and($export->json('data.profile.name'))->toBe('Io')
        ->and($export->json('data.saved_events'))->toHaveCount(1)
        ->and($export->json('data.saved_events.0.event_title'))->toBe('La mia serata')
        ->and($export->json('data.follows'))->toHaveCount(1)
        ->and($export->json('data.follows.0.id'))->toBe((int) $venue->getKey())
        ->and($export->json('data.devices'))->toHaveCount(1)
        ->and($export->json('data.notification_log'))->toHaveCount(1);
});

it('non consegna niente di nessun altro', function (): void {
    $io = User::factory()->create(['email' => 'io@example.test', 'name' => 'Io']);
    $altro = User::factory()->create(['email' => 'altro@example.test', 'name' => 'Un altro']);

    app(SaveOccurrences::class)->one($io, $this->mia);
    app(SaveOccurrences::class)->one($altro, $this->altrui);

    Follow::factory()->create(['user_id' => $altro->getKey()]);
    Device::factory()->create(['user_id' => $altro->getKey()]);
    NotificationLog::factory()->create(['user_id' => $altro->getKey()]);

    $export = $this->withToken($io->createToken('Telefono')->plainTextToken)
        ->getJson('/api/v1/me/export')
        ->assertOk();

    $tutto = json_encode($export->json('data'), JSON_UNESCAPED_UNICODE);

    expect($export->json('data.saved_events'))->toHaveCount(1)
        ->and($export->json('data.follows'))->toHaveCount(0)
        ->and($export->json('data.devices'))->toHaveCount(0)
        ->and($export->json('data.notification_log'))->toHaveCount(0)
        ->and($tutto)->not->toContain('altro@example.test')
        ->and($tutto)->not->toContain('La serata di un altro');
});

/**
 * Il diritto è quello di ricevere **ciò che si è dato**: la password, fosse
 * anche il suo hash, non è un dato fornito ma un segreto custodito, e un
 * export che la porta con sé la fa uscire dal server dentro un file che finirà
 * in una cartella Download.
 */
it('non fa uscire il segreto: nessun hash di password, nessun token', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('Telefono')->plainTextToken;

    $export = $this->withToken($token)->getJson('/api/v1/me/export')->assertOk();

    $tutto = (string) json_encode($export->json('data'));

    expect($tutto)->not->toContain('$2y$')
        ->not->toContain('password')
        ->not->toContain('remember_token')
        ->not->toContain(explode('|', $token)[1]);
});

it('non risponde a chi non porta un token', function (): void {
    $this->getJson('/api/v1/me/export')->assertUnauthorized();
});

/**
 * Lo stesso export si scarica anche dal sito, da chi non ha un'app (§15.8
 * espone l'endpoint, ma la pagina del profilo deve poter fare la stessa cosa).
 */
it('si scarica anche dal sito, e solo per chi è entrato', function (): void {
    $user = User::factory()->create(['email' => 'sito@example.test']);
    app(SaveOccurrences::class)->one($user, $this->mia);

    $this->get('/il-mio-profilo/dati')->assertRedirect(route('login'));

    $risposta = $this->actingAs($user)->get('/il-mio-profilo/dati')->assertOk();

    $risposta->assertHeader('Content-Disposition', 'attachment; filename="'.config()->string('app.name').'-dati.json"');

    expect($risposta->getContent())->toContain('sito@example.test')
        ->and($risposta->getContent())->toContain('La mia serata');
});
