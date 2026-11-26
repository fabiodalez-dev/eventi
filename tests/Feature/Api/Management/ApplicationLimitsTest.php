<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
 * I moduli «Registra il tuo locale», della rivendicazione e di «Registra la
 * tua organizzazione» nell'app, dopo la revisione di sicurezza del
 * completamento:
 *
 * - le tre letture rese pubbliche (Q2) contano la persona dal token anche
 *   fuori da `auth:sanctum`, sullo stesso secchio del sito; l'ospite conta
 *   per indirizzo, con il /64 per IPv6 (`IpBucket`);
 * - le scritture che l'app manda da sola accettano `Idempotency-Key`: lo
 *   stesso invio ripetuto riceve la risposta di prima invece di un doppione,
 *   anche in multipart con l'immagine. Senza la chiave tutto resta com'era.
 */
beforeEach(function (): void {
    Notification::fake();
    Storage::fake('local');
    $this->city = testCity();
});

/** Una richiesta con il token vero, come l'app: `Sanctum::actingAs` cambierebbe la guardia predefinita e nasconderebbe il problema. */
function letturaConToken(User $user, string $ip = '203.0.113.7'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withToken($user->createToken('telefono')->plainTextToken)
        ->getJson('/api/v1/venue-applications/lookup?q=ponte');
}

function letturaDaOspite(string $ip): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->withoutToken()->getJson('/api/v1/venue-applications/lookup?q=ponte');
}

/** @return array<string, mixed> */
function richiestaLocale(array $override = []): array
{
    return [
        'venue_name' => 'Circolo del Ponte',
        'type' => 'circolo',
        'address' => 'Via Roma 1',
        'municipality' => 'Padova',
        'contact_name' => 'Marta Rossi',
        'message' => 'Ogni venerdì musica dal vivo.',
        ...$override,
    ];
}

function richiesteDi(User $user): int
{
    return VenueApplication::query()->withoutGlobalScope(VenueApplication::AWAITING_EMAIL)->where('user_id', $user->id)->count();
}

// ------------------------------------------------------------------ limiti delle letture

it('conta le letture di chi manda il token sul suo secchio, non su quello dell indirizzo condiviso', function (): void {
    $marta = User::factory()->create();
    $luca = User::factory()->create();
    $token = $marta->createToken('telefono')->plainTextToken;

    for ($i = 0; $i < 60; $i++) {
        app('auth')->forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withToken($token)
            ->getJson('/api/v1/venue-applications/lookup?q=ponte')->assertOk();
    }
    app('auth')->forgetGuards();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withToken($token)
        ->getJson('/api/v1/venue-applications/lookup?q=ponte')->assertTooManyRequests();

    /* Un'altra persona dietro lo stesso NAT dell'operatore ha il suo secchio. */
    letturaConToken($luca)->assertOk();

    /* E il sito conta sullo stesso secchio dell'app: per Marta è finito anche lì. */
    app('auth')->forgetGuards();
    $this->withoutToken()->actingAs($marta)->get('/registra-il-tuo-locale')->assertTooManyRequests();
});

it('conta l ospite per indirizzo, con tutto il /64 in IPv6', function (): void {
    for ($i = 0; $i < 60; $i++) {
        letturaDaOspite('2001:db8:1:2::'.dechex($i + 1))->assertOk();
    }

    letturaDaOspite('2001:db8:1:2::ffff')->assertTooManyRequests();
    letturaDaOspite('2001:db8:9:9::1')->assertOk();
});

it('usa i limiti con nome sulle tre letture dell app e sulle pagine del sito che le mostrano', function (): void {
    $middleware = static fn (string $name): array => Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

    expect($middleware('api.v1.venue-applications.lookup'))->toContain('throttle:venue-catalogue-search')
        ->and($middleware('venue-applications.create'))->toContain('throttle:venue-catalogue-search')
        ->and($middleware('api.v1.me.venue-applications.options'))->toContain('throttle:venue-application-options')
        ->and($middleware('api.v1.venues.claim.show'))->toContain('throttle:venue-claim-target')
        ->and($middleware('venues.claim.create'))->toContain('throttle:venue-claim-target');
});

// ------------------------------------------------------------------ invii ripetuti

it('non crea un doppione quando l app ripete la richiesta di un locale con la stessa chiave', function (): void {
    $marta = User::factory()->create();
    Sanctum::actingAs($marta);
    $headers = ['Idempotency-Key' => 'bozza-venue-1234567890abcdef'];

    $first = $this->postJson('/api/v1/me/venue-applications', richiestaLocale(), $headers)->assertCreated();
    $again = $this->postJson('/api/v1/me/venue-applications', richiestaLocale(), $headers)->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($again->json('data.application.id'))->toBe($first->json('data.application.id'))
        ->and(richiesteDi($marta))->toBe(1);

    /* La stessa chiave con un altro contenuto non passa per buona. */
    $this->postJson('/api/v1/me/venue-applications', richiestaLocale(['venue_name' => 'Altro circolo']), $headers)->assertStatus(409);
    expect(richiesteDi($marta))->toBe(1);
});

it('non crea un doppione con l immagine, anche se il confine del multipart cambia', function (): void {
    $marta = User::factory()->create();
    Sanctum::actingAs($marta);
    $headers = ['Accept' => 'application/json', 'Idempotency-Key' => 'bozza-venue-immagine-12345678'];
    $image = UploadedFile::fake()->image('copertina.jpg', 800, 600);

    $this->post('/api/v1/me/venue-applications', richiestaLocale(['image' => $image]), $headers)->assertCreated();
    $this->post('/api/v1/me/venue-applications', richiestaLocale(['image' => $image]), $headers)->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');
    expect(richiesteDi($marta))->toBe(1);

    /* Un'altra immagine con la stessa chiave è un altro invio. */
    $this->post('/api/v1/me/venue-applications', richiestaLocale(['image' => UploadedFile::fake()->image('altra.jpg', 900, 700)]), $headers)->assertStatus(409);
    expect(richiesteDi($marta))->toBe(1);
});

it('non crea un doppione della rivendicazione né dell organizzazione con la stessa chiave', function (): void {
    $marta = User::factory()->create();
    Sanctum::actingAs($marta);
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Osteria del Ponte']);
    $claim = ['contact_name' => 'Marta Rossi', 'message' => 'Sono la titolare dal 2019.'];

    $this->postJson('/api/v1/venues/'.$venue->slug.'/claim', $claim, ['Idempotency-Key' => 'bozza-claim-1234567890abcd'])->assertCreated();
    $this->postJson('/api/v1/venues/'.$venue->slug.'/claim', $claim, ['Idempotency-Key' => 'bozza-claim-1234567890abcd'])->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');

    $organizer = ['venue_name' => 'Collettivo Portici', 'contact_name' => 'Marta Rossi'];
    $this->postJson('/api/v1/me/organizer-applications', $organizer, ['Idempotency-Key' => 'bozza-organizer-1234567890'])->assertCreated();
    $this->postJson('/api/v1/me/organizer-applications', $organizer, ['Idempotency-Key' => 'bozza-organizer-1234567890'])->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect(richiesteDi($marta))->toBe(2);
});

it('senza la chiave, come le versioni dell app già pubblicate, ogni invio è una richiesta', function (): void {
    $marta = User::factory()->create();
    Sanctum::actingAs($marta);

    $this->postJson('/api/v1/me/venue-applications', richiestaLocale())->assertCreated()->assertHeaderMissing('Idempotency-Replayed');
    $this->postJson('/api/v1/me/venue-applications', richiestaLocale(['venue_name' => 'Secondo circolo']))->assertCreated()->assertHeaderMissing('Idempotency-Replayed');

    expect(richiesteDi($marta))->toBe(2);
});
