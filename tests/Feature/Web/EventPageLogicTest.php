<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Models\EventOccurrence;
use App\Models\TicketTier;
use App\Models\Venue;
use App\Support\EventUrl;
use Carbon\Carbon;
use Tests\Support\VenueIsolationScenario;

/*
 * La revisione logica della scheda evento: ogni blocco compare solo quando ha
 * senso, e la pagina non dice due cose diverse sulla stessa serata.
 */

afterEach(function (): void {
    Carbon::setTestNow();
});

it('offers rides, calendar and poster only for dates that will still happen', function (): void {
    config(['carpool.enabled' => true]);
    $city = testCity();
    freezeLocal($city, '2026-09-07 12:00:00');
    $first = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:00:00', occurrence: ['status' => OccurrenceStatus::Cancelled]);
    $second = EventOccurrence::factory()->create([
        'event_id' => $first->event_id,
        'starts_at' => localInstant($city, '2026-09-17 21:00:00')->utc(),
        'ends_at' => null,
    ]);

    $html = $this->get(route('events.show', $first->event))->assertOk()
        ->assertSee(OccurrenceStatus::Cancelled->label())
        ->getContent();

    expect($html)
        ->not->toContain('ride-title-'.$first->id)
        ->toContain('ride-title-'.$second->id)
        ->not->toContain(route('events.calendar', ['slug' => $first->event->slug, 'occurrence' => $first->url_number]))
        ->toContain(route('events.calendar', ['slug' => $first->event->slug, 'occurrence' => $second->url_number]));
});

it('says the series goes on when a past date is opened, and links the next one', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-07 12:00:00');
    $past = occurrenceAtLocal($city, testCategory(), '2026-08-30 21:00:00');
    $next = EventOccurrence::factory()->create([
        'event_id' => $past->event_id,
        'starts_at' => localInstant($city, '2026-09-13 21:00:00')->utc(),
        'ends_at' => null,
    ]);

    $this->get(EventUrl::occurrence($past))->assertOk()
        ->assertSee(__('events.detail.date_past'))
        ->assertSee(EventUrl::occurrence($next->setRelation('event', $past->event)), false)
        ->assertDontSee(__('events.detail.finished'));
});

it('still calls an event finished when no dates are left', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-07 12:00:00');
    $past = occurrenceAtLocal($city, testCategory(), '2026-08-30 21:00:00');

    $this->get(EventUrl::occurrence($past))->assertOk()
        ->assertSee(__('events.detail.finished'))
        ->assertDontSee(__('events.detail.date_past'));
});

it('prices a date with its own listing at that listing, not the event one', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-07 12:00:00');
    $first = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:00:00', event: ['price_type' => PriceType::Ticket, 'price_min' => 10, 'price_max' => 20]);
    $second = EventOccurrence::factory()->create([
        'event_id' => $first->event_id,
        'starts_at' => localInstant($city, '2026-09-17 21:00:00')->utc(),
        'ends_at' => null,
    ]);
    TicketTier::factory()->create(['event_id' => $first->event_id, 'name' => 'Intero evento', 'price' => 10]);
    TicketTier::factory()->create(['event_id' => $first->event_id, 'occurrence_id' => $second->id, 'name' => 'Serata speciale', 'price' => 25]);

    $html = $this->get(EventUrl::occurrence($second->setRelation('event', $first->event)))->assertOk()->getContent();

    expect($html)->toContain('Serata speciale')->not->toContain('Intero evento');
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $price = (new DOMXPath($document))->query('//section[@aria-labelledby="prezzo-evento"]')->item(0)?->textContent ?? '';
    expect($price)->toContain('25')->not->toContain('10');
});

it('writes the previous date of a moved date in words', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-07 12:00:00');
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:00:00', occurrence: [
        'previous_starts_at' => localInstant($city, '2026-09-08 21:00:00')->utc(),
    ]);

    $html = $this->get(route('events.show', $date->event))->assertOk()->getContent();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $dates = (new DOMXPath($document))->query('//section[@aria-labelledby="date-evento"]')->item(0)?->textContent ?? '';

    // Nei dati strutturati la data resta ISO, com'è giusto; in pagina si legge.
    expect($dates)->toContain('8 settembre 2026 alle 21:00')->not->toContain('2026-09-08T');
});

it('keeps the visitor on the event after signing in from the collection link', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-07 12:00:00');
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:00:00');
    $url = route('events.show', $date->event);

    $this->get($url)->assertOk()
        ->assertSee(route('login', ['intended' => $url]), false)
        ->assertDontSee(route('login', ['redirect' => $url]), false);
});

it('lists other events of the venue the date actually takes place at', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-07 12:00:00');
    $elsewhere = Venue::factory()->for($city)->create(['name' => 'Locale della data spostata']);
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:00:00', occurrence: ['venue_id' => $elsewhere->id]);
    $there = occurrenceAtLocal($city, testCategory(), '2026-09-12 21:00:00', event: ['venue_id' => $elsewhere->id, 'title' => 'Serata nel locale nuovo']);
    occurrenceAtLocal($city, testCategory(), '2026-09-12 21:00:00', event: ['venue_id' => $date->event->venue_id, 'title' => 'Serata nel locale vecchio']);

    $this->get(EventUrl::occurrence($date))->assertOk()
        ->assertSee('Serata nel locale nuovo')
        ->assertDontSee('Serata nel locale vecchio');
    expect($there->event->venue_id)->toBe($elsewhere->id);
});

it('hides sharing and reporting in the preview of a draft', function (): void {
    $s = VenueIsolationScenario::make();

    $html = $this->actingAs($s->ownerA)->get(route('events.preview', $s->draftEventA))->assertOk()->getContent();

    expect($html)->not->toContain('aria-labelledby="condividi-evento"')
        ->not->toContain(route('events.report', ['slug' => $s->draftEventA->slug]));
});
