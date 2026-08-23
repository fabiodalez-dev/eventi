<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\SavedEvent;
use App\Models\User;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('restituisce la scheda di un evento con le sue date future', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $occorrenza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Rassegna d\'autunno']);
    $event = $occorrenza->event;

    $event->occurrences()->create([
        'starts_at' => localInstant($city, '2026-09-13 21:00:00')->utc(),
        'ends_at' => null,
        'is_all_day' => false,
    ]);

    $data = $this->getJson('/api/v1/events/'.$event->slug)->assertOk()->json('data');

    expect($data['slug'])->toBe($event->slug)
        ->and($data['title'])->toBe('Rassegna d\'autunno')
        ->and($data['occurrences'])->toHaveCount(2)
        ->and($data['occurrences'][0]['starts_at'])->toBe('2026-09-06T21:00:00+02:00')
        ->and($data)->toHaveKeys(['poster', 'venue', 'price', 'booking', 'updated_at'])
        ->and($data)->not->toHaveKey('editorial_score');
});

it('risponde 404 per uno slug che non esiste o non è pubblicato', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $bozza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: [
        'title' => 'Bozza',
        'status' => EventStatus::Draft,
    ]);

    $this->getJson('/api/v1/events/mai-esistito')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    $this->getJson('/api/v1/events/'.$bozza->event->slug)->assertNotFound();
});

it('propone eventi simili della stessa categoria escludendo quello aperto', function (): void {
    $city = testCity();
    $musica = testCategory(['name' => 'Musica dal vivo']);
    $teatro = testCategory(['name' => 'Teatro e danza']);

    freezeLocal($city, '2026-09-05 09:00:00');

    $aperto = occurrenceAtLocal($city, $musica, '2026-09-06 21:00:00', event: ['title' => 'Questo']);
    occurrenceAtLocal($city, $musica, '2026-09-07 21:00:00', event: ['title' => 'Simile']);
    occurrenceAtLocal($city, $teatro, '2026-09-08 21:00:00', event: ['title' => 'Di un\'altra categoria']);

    $data = $this->getJson('/api/v1/events/'.$aperto->event->slug.'/similar')->assertOk()->json('data');

    expect(array_column($data, 'title'))->toBe(['Simile']);
});

it('restituisce una singola data e la nega se non è pubblica', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $pubblica = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Visibile']);
    $bozza = occurrenceAtLocal($city, $category, '2026-09-06 22:00:00', event: [
        'title' => 'Invisibile',
        'status' => EventStatus::Draft,
    ]);

    $this->getJson('/api/v1/occurrences/'.$pubblica->getKey())
        ->assertOk()
        ->assertJsonPath('data.occurrence_id', (int) $pubblica->getKey())
        ->assertJsonPath('data.title', 'Visibile');

    $this->getJson('/api/v1/occurrences/'.$bozza->getKey())->assertNotFound();
    $this->getJson('/api/v1/occurrences/999999')->assertNotFound();
});

it('dice anche sulla singola data se è salvata, ma solo a chi è autenticato', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $occorrenza = occurrenceAtLocal($city, $category, '2026-09-06 21:00:00');
    $user = User::factory()->create();
    SavedEvent::create(['user_id' => $user->getKey(), 'occurrence_id' => $occorrenza->getKey()]);

    $anonimo = $this->getJson('/api/v1/occurrences/'.$occorrenza->getKey())->assertOk()->json('data');
    $autenticato = $this->actingAs($user, 'sanctum')->getJson('/api/v1/occurrences/'.$occorrenza->getKey())->assertOk()->json('data');

    expect($anonimo)->not->toHaveKey('is_saved')
        ->and($autenticato['is_saved'])->toBeTrue();
});
