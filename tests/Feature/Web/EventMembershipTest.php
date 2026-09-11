<?php

declare(strict_types=1);

use App\DTOs\EventFilters;
use App\Enums\MembershipRequirement;
use App\Models\Event;
use App\Models\Venue;
use App\Services\Search\ContextualFacets;
use App\Services\Search\EventFinder;
use App\Services\Search\TonightDiscovery;

it('filters membership per event without inheriting the venue requirement', function (): void {
    $city = testCity();
    $category = testCategory();
    freezeLocal($city, '2026-09-11 12:00');
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id, 'requires_membership' => true]);
    $required = occurrenceAtLocal($city, $category, '2026-09-11 21:00', event: ['content_details' => ['membership' => 'required']], venue: $venue);
    $notRequired = occurrenceAtLocal($city, $category, '2026-09-11 22:00', event: ['content_details' => ['membership' => 'not_required']], venue: $venue);
    $unknown = occurrenceAtLocal($city, $category, '2026-09-11 23:00', event: ['price_type' => 'free'], venue: $venue);
    $finder = app(EventFinder::class);
    expect($finder->query($city, EventFilters::fromArray(['membership' => 'required']))->get()->modelKeys())->toBe([$required->id]);
    expect($finder->query($city, EventFilters::fromArray(['membership' => 'not_required']))->get()->modelKeys())->toBe([$notRequired->id]);
    expect($unknown->event->membershipRequirement())->toBeNull();
    foreach (['/eventi/'.$unknown->event->slug, '/eventi/'.$unknown->event->slug.'/1'] as $url) {
        $this->get($url)->assertOk()
            ->assertDontSee(__('filters.membership.unknown'))
            ->assertDontSee('data-event-membership', false);
    }
    $this->get('/eventi/'.$required->event->slug.'/1')->assertOk()->assertSee(__('filters.membership.required'));
    $this->get('/eventi/'.$notRequired->event->slug.'/1')->assertOk()->assertSee(__('filters.membership.not_required'));
    $this->getJson('/api/v1/events?membership=not_required')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.occurrence_id', $notRequired->id);
    $this->getJson('/api/v1/events/'.$required->event->slug)->assertOk()->assertJsonPath('data.content_details.membership', 'required');
    expect(app(TonightDiscovery::class)->practical($unknown)[1]['value'])->toBe(__('tonight.unknown'));
});

it('keeps legacy event membership prices but allows an explicit event declaration', function (): void {
    $event = new Event(['price_type' => 'membership']);
    expect($event->membershipRequirement())->toBe(MembershipRequirement::Required);
    $event->content_details = ['membership' => 'not_required'];
    expect($event->membershipRequirement())->toBe(MembershipRequirement::NotRequired);
    $filters = EventFilters::fromArray(['membership' => 'not_required']);
    expect($filters->withVenue('circolo')->toQueryString()['membership'])->toBe('not_required');
    expect($filters->cleared()->membership)->toBeNull();
});

it('offers other towns while narrowing dependent venues and districts', function (): void {
    $city = testCity();
    $category = testCategory();
    freezeLocal($city, '2026-09-11 12:00');
    foreach (['Padova', 'Abano Terme'] as $town) {
        $venue = Venue::factory()->approved()->create(['city_id' => $city->id, 'municipality' => $town, 'zone' => $town.' centro']);
        occurrenceAtLocal($city, $category, '2026-09-11 21:00', venue: $venue);
    }
    $counts = app(ContextualFacets::class)->build($city, EventFilters::fromArray(['municipality' => 'Padova']));
    expect(array_keys($counts['municipality']))->toEqualCanonicalizing(['Padova', 'Abano Terme']);
    expect(array_keys($counts['zone']))->toBe(['Padova centro']);
    expect($counts['venue'])->toHaveCount(1);
    $this->getJson('/api/v1/events/facets?municipality=Padova')->assertOk()->assertJsonPath('data.municipality.Abano Terme', 1)->assertJsonPath('data.zone.Padova centro', 1);
    $this->get('/mappa?municipality=Padova')->assertOk()->assertSee('data-searchable-filter', false)->assertSee('data-map-browser', false);
});
