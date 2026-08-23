<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Prepara una serie di date consecutive, una al giorno.
 */
function serieDiDate(int $quante): array
{
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    for ($giorno = 6; $giorno < 6 + $quante; $giorno++) {
        occurrenceAtLocal($city, $category, sprintf('2026-09-%02d 21:00:00', $giorno), event: [
            'title' => 'Serata '.$giorno,
        ]);
    }

    return [$city, $category];
}

/*
 * §13.6: paginazione a cursore ovunque, mai offset sulle liste temporali.
 */
it('sfoglia con il cursore senza ripetere né saltare elementi', function (): void {
    serieDiDate(7);

    $prima = $this->getJson('/api/v1/events?limit=3')->assertOk();

    expect($prima->json('meta.has_more'))->toBeTrue()
        ->and($prima->json('meta.next_cursor'))->toBeString();

    $seconda = $this->getJson('/api/v1/events?limit=3&cursor='.$prima->json('meta.next_cursor'))->assertOk();
    $terza = $this->getJson('/api/v1/events?limit=3&cursor='.$seconda->json('meta.next_cursor'))->assertOk();

    $ids = [
        ...array_column($prima->json('data'), 'occurrence_id'),
        ...array_column($seconda->json('data'), 'occurrence_id'),
        ...array_column($terza->json('data'), 'occurrence_id'),
    ];

    expect($ids)->toHaveCount(7)
        ->and(array_unique($ids))->toHaveCount(7)
        ->and($terza->json('meta.has_more'))->toBeFalse()
        ->and($terza->json('meta.next_cursor'))->toBeNull();
});

it('sa sfogliare anche le liste ordinate per rilevanza e popolarità', function (): void {
    serieDiDate(5);

    foreach (['relevance', 'popular', 'start'] as $sort) {
        $prima = $this->getJson('/api/v1/events?limit=2&sort='.$sort)->assertOk();
        $seconda = $this->getJson('/api/v1/events?limit=2&sort='.$sort.'&cursor='.$prima->json('meta.next_cursor'))->assertOk();

        $ids = [
            ...array_column($prima->json('data'), 'occurrence_id'),
            ...array_column($seconda->json('data'), 'occurrence_id'),
        ];

        expect(array_unique($ids))->toHaveCount(4, "sort={$sort}");
    }
});

/*
 * Un cursore alterato non è "prima pagina": è un 400. Trattarlo come assente
 * farebbe scorrere per sempre lo stesso inizio di lista senza alcun segnale.
 */
it('rifiuta un cursore illeggibile con 400', function (): void {
    serieDiDate(2);

    $this->getJson('/api/v1/events?cursor=@@@non-e-un-cursore@@@')
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'INVALID_CURSOR');
});

it('non accetta pagine più grandi di cinquanta elementi', function (): void {
    serieDiDate(2);

    $this->getJson('/api/v1/events?limit=51')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['code', 'message', 'fields' => ['limit']]]);

    $this->getJson('/api/v1/events?limit=50')->assertOk();
});

/*
 * §13: ETag e Cache-Control sulle liste pubbliche, con il 304 funzionante.
 */
it('dichiara ETag e Cache-Control pubblici e risponde 304 a chi ha già la stessa versione', function (): void {
    serieDiDate(3);

    $prima = $this->getJson('/api/v1/events?limit=3')->assertOk();

    $etag = $prima->headers->get('ETag');

    expect($etag)->not->toBeNull()
        ->and($prima->headers->get('Cache-Control'))->toContain('public')
        ->and($prima->headers->get('Cache-Control'))->toContain('max-age=60')
        ->and($prima->headers->get('Cache-Control'))->toContain('stale-while-revalidate=300');

    $seconda = $this->getJson('/api/v1/events?limit=3', ['If-None-Match' => $etag]);

    expect($seconda->getStatusCode())->toBe(304)
        ->and($seconda->getContent())->toBe('');
});

it('cambia ETag quando cambia il contenuto', function (): void {
    [$city, $category] = serieDiDate(2);

    $primo = $this->getJson('/api/v1/events')->assertOk()->headers->get('ETag');

    occurrenceAtLocal($city, $category, '2026-09-09 21:00:00', event: ['title' => 'Nuova serata']);

    $secondo = $this->getJson('/api/v1/events')->assertOk()->headers->get('ETag');

    expect($secondo)->not->toBe($primo);
});

/*
 * Una risposta che porta `is_saved` parla di un utente solo: non può finire
 * in una cache condivisa.
 */
it('non dichiara pubblica una risposta autenticata', function (): void {
    serieDiDate(2);

    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/events')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))->not->toContain('public');
});

/*
 * §13.4: 60 richieste al minuto per chi non è autenticato, con le
 * intestazioni `X-RateLimit-*`.
 */
it('dichiara i limiti di frequenza e li fa rispettare', function (): void {
    testCity();

    $prima = $this->getJson('/api/v1/cities')->assertOk();

    expect($prima->headers->get('X-RateLimit-Limit'))->toBe('60');

    for ($tentativo = 0; $tentativo < 60; $tentativo++) {
        $this->getJson('/api/v1/cities');
    }

    $ultima = $this->getJson('/api/v1/cities');

    expect($ultima->getStatusCode())->toBe(429)
        ->and($ultima->json('error.code'))->toBe('RATE_LIMITED')
        ->and($ultima->headers->get('Retry-After'))->not->toBeNull();
});

it('concede il doppio delle richieste a chi è autenticato', function (): void {
    testCity();

    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/cities')->assertOk();

    expect($response->headers->get('X-RateLimit-Limit'))->toBe('120');
});
