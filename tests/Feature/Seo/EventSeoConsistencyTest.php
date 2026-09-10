<?php

use App\DTOs\EventFilters;
use App\Enums\PriceType;
use App\Enums\TicketTierStatus;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\TicketTier;
use App\Models\Venue;
use App\Services\Seo\EventListingMeta;
use App\Services\Seo\PublicOffers;
use App\Services\Seo\StructuredData;
use App\Support\EventUrl;

/** @return list<array<string, mixed>> */
function seoEventNodes(string $html): array
{
    preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $matches);

    return array_map(fn ($json) => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
}

/** @return list<string> */
function seoEventSitemapUrls(string $xml): array
{
    $document = new SimpleXMLElement($xml);
    $document->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

    return array_map(fn ($url) => (string) $url, $document->xpath('//s:url/s:loc'));
}

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-01 12:00');
    $this->date = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00', event: ['price_type' => PriceType::Free]);
    $this->event = $this->date->event;
});

it('consolidates a single event onto its permanent date URL in HTML schema and sitemap', function (): void {
    $canonical = EventUrl::occurrence($this->date);
    foreach ([route('events.show', $this->event), $canonical] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        expect($html)->toContain('<link rel="canonical" href="'.$canonical.'">');
        $nodes = seoEventNodes($html);
        $event = collect($nodes)->firstWhere('@type', 'Event');
        expect($event['url'])->toBe($canonical)->and($event['@id'])->toBe($canonical.'#event');
    }
    $sitemap = $this->get('/sitemap-eventi-1.xml')->assertOk()->getContent();
    expect(seoEventSitemapUrls($sitemap))->toContain($canonical)->not->toContain(route('events.show', $this->event));
});

it('keeps a series a collection even when only one future date remains', function (): void {
    EventOccurrence::factory()->create(['event_id' => $this->event->id, 'starts_at' => localInstant($this->city, '2026-08-15 21:00')->utc()]);
    $url = route('events.show', $this->event);
    $html = $this->get($url)->assertOk()->getContent();
    expect($html)->toContain('<link rel="canonical" href="'.$url.'">');
    expect(collect(seoEventNodes($html))->pluck('@type')->all())->toContain('CollectionPage')->not->toContain('Event');
});

it('keeps organizer independent of original and moved venues in JSON-LD and visible HTML', function (): void {
    $organizer = Organizer::create(['city_id' => $this->city->id, 'name' => 'Associazione Organizzatrice', 'is_active' => true]);
    $legacy = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Organizzatore precedente']);
    $moved = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Teatro nuova sede']);
    $this->event->update(['organizer_id' => $organizer->id, 'content_details' => ['organizer_venue_id' => $legacy->id]]);
    $this->date->update(['venue_id' => $moved->id]);
    $this->date->refresh();
    $node = app(StructuredData::class)->event($this->event->fresh(), $this->date);
    expect($node['organizer']['name'])->toBe($organizer->name)->and($node['location']['name'])->toBe($moved->name);
    $this->get(EventUrl::occurrence($this->date))
        ->assertOk()->assertSee($organizer->name)->assertSee($moved->name)->assertDontSee($legacy->name);
});

it('defaults to the original event venue when no organizer is specified', function (): void {
    $this->event->update(['organizer_id' => null, 'organizer_name' => null, 'content_details' => []]);
    $moved = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $this->date->update(['venue_id' => $moved->id]);
    $node = app(StructuredData::class)->event($this->event->fresh(), $this->date->fresh());
    expect($node['organizer']['name'])->toBe($this->event->venue->name)->and($node['location']['name'])->toBe($moved->name);
});

it('retains an explicitly selected venue as organizer when the event moves', function (): void {
    $organizer = $this->event->venue;
    $this->event->update(['content_details' => ['organizer_venue_id' => $organizer->id]]);
    $newVenue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $this->date->update(['venue_id' => $newVenue->id]);
    $node = app(StructuredData::class)->event($this->event->fresh(), $this->date->fresh());
    expect($node['organizer']['name'])->toBe($organizer->name)->and($node['location']['name'])->toBe($newVenue->name);
});

it('removes outdated offers while preserving the historic event', function (): void {
    freezeLocal($this->city, '2026-09-20 12:00');
    expect(app(PublicOffers::class)->for($this->event, $this->date))->toBe([]);
    $this->get(EventUrl::occurrence($this->date))->assertOk();
});

it('does not claim preorder availability for tickets not yet on sale', function (): void {
    TicketTier::factory()->create(['event_id' => $this->event->id, 'occurrence_id' => $this->date->id, 'price' => 12, 'status' => TicketTierStatus::NotYetOnSale]);
    $offers = app(PublicOffers::class)->for($this->event->fresh(), $this->date->fresh());
    expect($offers)->toHaveCount(1)->and($offers[0])->not->toHaveKey('availability');
});

it('uses significant event and occurrence changes for sitemap dates', function (): void {
    freezeLocal($this->city, '2026-09-02 12:00');
    $this->event->update(['title' => 'Titolo aggiornato']);
    $html = $this->get('/sitemap-eventi-1.xml')->assertOk()->getContent();
    expect($html)->toContain($this->event->updated_at->toAtomString());
    $this->get('/sitemap.xml')->assertOk()->assertDontSee('<lastmod>', false);
});

it('permits reading search noindex and indexes only bounded useful filters', function (): void {
    $this->get('/robots.txt')->assertOk()->assertDontSee('Disallow: /cerca');
    $this->get('/cerca?q=jazz')->assertOk()->assertSee('noindex, follow');
    $meta = app(EventListingMeta::class);
    expect($meta->build($this->city, EventFilters::fromArray(['category' => $this->category->slug]), 3)->indexable)->toBeTrue()
        ->and($meta->build($this->city, EventFilters::fromArray(['category' => $this->category->slug, 'price' => 'free']), 3)->indexable)->toBeFalse()
        ->and($meta->build($this->city, EventFilters::fromArray(['municipality' => 'Este']), 0)->indexable)->toBeFalse()
        ->and($meta->build($this->city, EventFilters::fromArray(['budget' => 12]), 3)->indexable)->toBeFalse();
});

it('refreshes the date sitemap after deleting a ticket tier', function (): void {
    $tier = TicketTier::factory()->create(['event_id' => $this->event->id, 'occurrence_id' => $this->date->id]);
    $this->get('/sitemap-eventi-1.xml')->assertOk();
    freezeLocal($this->city, '2026-09-03 12:00');
    $tier->delete();
    $this->get('/sitemap-eventi-1.xml')->assertOk()->assertSee($this->date->fresh()->updated_at->toAtomString(), false);
});

it('corrects only the unchanged acoustic demo and leaves edited descriptions untouched', function (): void {
    $this->event->update(['slug' => 'concerto-trio-acustico', 'title' => 'Concerto: Trio Acustico', 'subtitle' => 'Serata dal vivo già iniziata',
        'description' => 'Descrizione verificata dalla redazione.', 'short_description' => 'Concerto acustico in corso, ingresso libero.',
        'price_type' => PriceType::Ticket, 'price_min' => 8, 'price_max' => 8, 'is_demo' => false]);
    $migration = require database_path('migrations/2026_09_10_170000_correct_acoustic_demo_editorial_data.php');
    $migration->up();
    expect($this->event->fresh()->is_demo)->toBeFalse();
    $this->event->update(['description' => "Un trio acustico che suona da mezz'ora davanti a un pubblico raccolto. Ingresso libero, cassa per le consumazioni al bancone."]);
    $migration->up();
    expect($this->event->fresh()->is_demo)->toBeTrue()
        ->and($this->event->fresh()->short_description)->toBe('Concerto acustico dal vivo. Ingresso: 8 euro.');
    $this->get(route('events.show', $this->event))->assertOk()->assertSee('noindex, follow');
});
