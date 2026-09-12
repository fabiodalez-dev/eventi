<?php

declare(strict_types=1);

use App\Models\User;
use App\Rules\RealImage;
use Illuminate\Support\Facades\Validator;
use Tests\Support\ImageFixtures;

/**
 * Tre tetti che mancavano, tutti sulla stessa risorsa: lo spazio disco e la
 * memoria di un hosting condiviso con quota da 10 GB, già esaurita una volta.
 */
beforeEach(function (): void {
    testCity();
});

/**
 * La bomba di decompressione.
 *
 * Il limite sul peso del file non basta: PNG e WebP comprimono a tinta unita
 * in modo spettacolare, e un'immagine di 30.000 x 30.000 pixel pesa poche
 * centinaia di kilobyte — passava i 12 MB, passava la misura minima, passava i
 * magic byte. Poi ImageMagick la apriva allocando quattro byte per pixel, e su
 * hosting condiviso l'esito non è un errore: è il processo ucciso dal limite
 * di memoria, con dentro la richiesta di chiunque altro.
 */
it('rifiuta un’immagine con troppi pixel anche quando il file è leggero', function (): void {
    /* Come il test gemello sul peso: si abbassa il tetto invece di generare
       un'immagine da novecento megapixel dentro la suite. */
    config()->set('media.max_pixels', 300_000);

    $validatore = Validator::make(
        ['file' => ImageFixtures::upload('enorme.jpg', ImageFixtures::jpeg(800, 800))],
        ['file' => [new RealImage]],
    );

    expect($validatore->fails())->toBeTrue()
        ->and($validatore->errors()->first('file'))->toContain('megapixel');
});

it('lascia passare un’immagine di misura normale', function (): void {
    $validatore = Validator::make(
        ['file' => ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg(800, 800))],
        ['file' => [new RealImage]],
    );

    expect($validatore->fails())->toBeFalse();
});

/**
 * `keys.*` limitava la lunghezza di ogni elemento e nulla limitava quanti ce
 * ne fossero: `DeviceController::store()` scriveva l'array intero in una
 * colonna JSON, quindi un account qualsiasi depositava megabyte per riga.
 */
it('rifiuta un array di chiavi push gonfiato o con nomi inventati', function (): void {
    /* Un token vero e non `Sanctum::actingAs`: questo endpoint scrive
       `device_id` sul token corrente, quindi vuole un PersonalAccessToken
       reale — è il motivo per cui i test esistenti usano `withToken`. */
    $utente = User::factory()->create();
    $token = $utente->createToken('Telefono')->plainTextToken;

    $gonfio = [];

    for ($i = 0; $i < 500; $i++) {
        $gonfio['chiave'.$i] = str_repeat('a', 255);
    }

    $this->withToken($token)->postJson('/api/v1/me/devices', [
        'platform' => 'web',
        'endpoint' => 'https://push.example.test/abc',
        'keys' => $gonfio,
    ])->assertStatus(422);

    /* E le due chiavi vere del protocollo Web Push passano. */
    $this->withToken($token)->postJson('/api/v1/me/devices', [
        'platform' => 'web',
        'endpoint' => 'https://push.example.test/abc',
        'keys' => ['p256dh' => 'chiave-pubblica', 'auth' => 'segreto'],
    ])->assertSuccessful();
});

it('rifiuta un endpoint push che non sia https', function (): void {
    $utente = User::factory()->create();
    $token = $utente->createToken('Telefono')->plainTextToken;

    foreach (['http://interno.local/push', 'http://169.254.169.254/latest/meta-data', 'non-un-indirizzo'] as $cattivo) {
        $this->withToken($token)->postJson('/api/v1/me/devices', [
            'platform' => 'web',
            'endpoint' => $cattivo,
            'keys' => ['p256dh' => 'x', 'auth' => 'y'],
        ])->assertStatus(422);
    }
});

/**
 * Ogni endpoint diverso era una riga nuova, e l'endpoint lo scrive chi chiama:
 * senza tetto, un account qualsiasi depositava righe a piacere.
 */
it('ricicla il dispositivo più vecchio invece di accumularne senza fine', function (): void {
    config()->set('account.max_devices_per_user', 3);

    $utente = User::factory()->create();
    $this->actingAs($utente);

    for ($i = 0; $i < 6; $i++) {
        $this->postJson(route('account.push.store'), [
            'endpoint' => "https://push.example.test/browser-{$i}",
            'keys' => ['p256dh' => 'chiave', 'auth' => 'segreto'],
        ])->assertSuccessful();
    }

    /* Sei iscrizioni, tre attive: le prime tre sono state revocate, non
       cancellate — la revoca è ciò che dice a §15.6 di tornare all'email. */
    expect($utente->devices()->whereNull('revoked_at')->count())->toBe(3)
        ->and($utente->devices()->count())->toBe(3);

    $this->postJson(route('account.push.store'), ['endpoint' => 'https://push.example.test/browser-0', 'keys' => ['p256dh' => 'chiave', 'auth' => 'segreto']])->assertSuccessful();
    expect($utente->devices()->count())->toBe(3)->and($utente->devices()->whereNull('revoked_at')->count())->toBe(3);
});
