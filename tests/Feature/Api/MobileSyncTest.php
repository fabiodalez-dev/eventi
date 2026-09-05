<?php

declare(strict_types=1);

use Carbon\Carbon;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('consegna tombstone per una occorrenza rimossa dopo l ultimo sync', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $since = now('UTC')->subMinute()->toIso8601String();

    $occurrence->delete();

    $response = $this->getJson('/api/v1/sync?'.http_build_query(['since' => $since, 'days' => 30]))
        ->assertOk();

    expect($response->json('meta.deleted_ids'))->toContain((int) $occurrence->getKey())
        ->and($response->json('meta.server_time'))->toBeString()
        ->and($response->json('meta.window_days'))->toBe(30);
});

it('rispedisce una occorrenza quando cambia un contenuto dipendente', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $since = now('UTC')->toIso8601String();

    Carbon::setTestNow(now()->addMinute());
    $occurrence->event->venue?->forceFill(['name' => 'Nome aggiornato'])->save();

    $response = $this->getJson('/api/v1/sync?'.http_build_query(['since' => $since, 'days' => 30]))
        ->assertOk();

    expect(collect($response->json('data'))->pluck('occurrence_id')->all())
        ->toContain((int) $occurrence->getKey());
});
