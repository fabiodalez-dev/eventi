<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * `/api/v1/sync`: niente `since` remoto a piacere (era una scansione della
 * storia intera a ogni richiesta) e le rimozioni solo sulla prima pagina.
 *
 * Politica delle password: in produzione si rifiutano quelle già finite in un
 * furto di credenziali.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('rifiuta un since più vecchio della finestra massima', function (): void {
    $this->getJson('/api/v1/sync?'.http_build_query(['since' => '1970-01-01T00:00:00+00:00']))
        ->assertUnprocessable();

    $this->getJson('/api/v1/sync?'.http_build_query(['since' => now('UTC')->subDays(5)->toIso8601String()]))
        ->assertOk();
});

it('manda deleted_ids solo con la prima pagina del cursore', function (): void {
    occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    occurrenceAt($this->city, $this->category, '2026-09-13 19:00:00');
    $removed = occurrenceAt($this->city, $this->category, '2026-09-14 19:00:00');
    $since = now('UTC')->subMinute()->toIso8601String();
    $removed->delete();

    $first = $this->getJson('/api/v1/sync?'.http_build_query(['since' => $since, 'limit' => 1]))->assertOk();
    expect($first->json('meta.deleted_ids'))->toContain((int) $removed->getKey());

    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->toBeString();

    $this->getJson('/api/v1/sync?'.http_build_query(['since' => $since, 'limit' => 1, 'cursor' => $cursor]))
        ->assertOk()
        ->assertJsonPath('meta.deleted_ids', []);
});

it('in produzione rifiuta una password già finita in un furto di credenziali', function (): void {
    $leaked = 'Password1234';
    $hash = mb_strtoupper(sha1($leaked));
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(mb_substr($hash, 5).":4242\r\n")]);

    app()->detectEnvironment(static fn (): string => 'production');

    expect(Validator::make(['p' => $leaked], ['p' => Password::defaults()])->fails())->toBeTrue()
        ->and(Validator::make(['p' => 'una-frase-mai-vista-prima'], ['p' => Password::defaults()])->fails())->toBeFalse();

    app()->detectEnvironment(static fn (): string => 'testing');
});
