<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\EventOccurrence;
use App\Support\EventUrl;

it('assegna numeri progressivi propri di ciascun evento', function (): void {
    $city = testCity();
    $category = testCategory();
    freezeLocal($city, '2026-09-01 12:00');
    $first = occurrenceAtLocal($city, $category, '2026-09-10 21:30');
    $second = EventOccurrence::factory()->create(['event_id' => $first->event_id]);
    $other = occurrenceAtLocal($city, $category, '2026-09-11 21:30');

    expect($first->url_number)->toBe(1)->and($second->url_number)->toBe(2)
        ->and($other->url_number)->toBe(1);
    $url = EventUrl::occurrence($first);
    expect($url)->toBe(url('/eventi/'.$first->event->slug.'/1'));
    $this->get($url)->assertOk()->assertSee('<link rel="canonical" href="'.$url.'">', false);
    $this->get('/eventi/'.$first->event->slug.'/999')->assertNotFound();
    $this->get('/eventi/'.$first->event->slug.'/date/'.$first->id)->assertNotFound();
    $this->getJson('/api/v1/events/'.$first->event->slug.'/dates/1')->assertOk()
        ->assertJsonPath('data.occurrence_id', $first->id)
        ->assertJsonPath('data.url_number', 1)
        ->assertJsonPath('data.date_url', $url);
    $this->getJson('/api/v1/events/'.$first->event->slug.'/dates/999')->assertNotFound();
});

it('non cambia URL riprogrammando o rinominando e non riutilizza numeri eliminati', function (): void {
    $city = testCity();
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:30');
    $url = EventUrl::occurrence($date);
    $date->update(['starts_at' => $date->starts_at->copy()->addDays(3)]);
    $date->event->update(['title' => 'Titolo aggiornato']);
    expect(EventUrl::occurrence($date->fresh()))->toBe($url);
    $date->forceDelete();
    $next = EventOccurrence::factory()->create(['event_id' => $date->event_id]);
    expect($next->url_number)->toBe(2);
});

it('non espone le repliche di una bozza', function (): void {
    $city = testCity();
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:30');
    $url = EventUrl::occurrence($date);
    $date->event->update(['status' => EventStatus::Draft]);
    $this->get($url)->assertNotFound();
    $this->getJson('/api/v1/events/'.$date->event->slug.'/dates/'.$date->url_number)->assertNotFound();
});

it('filtra le repliche e collega la data corretta anche dal pannello mappa', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-10 12:00');
    $today = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:30');
    $tomorrow = EventOccurrence::factory()
        ->startingAt(localInstant($city, '2026-09-11 21:30')->utc())
        ->create(['event_id' => $today->event_id]);

    $this->get('/eventi/domani')->assertOk()
        ->assertSee('href="'.EventUrl::occurrence($tomorrow).'"', false)
        ->assertDontSee('href="'.EventUrl::occurrence($today).'"', false);
    $this->get(route('map.venue', ['venue' => $today->event->venue_id, 'date' => 'tomorrow']))
        ->assertOk()->assertSee('href="'.EventUrl::occurrence($tomorrow).'"', false)
        ->assertDontSee('href="'.EventUrl::occurrence($today).'"', false);
});
