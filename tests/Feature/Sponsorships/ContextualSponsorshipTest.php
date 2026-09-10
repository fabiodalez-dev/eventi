<?php

declare(strict_types=1);

use App\DTOs\EventFilters;
use App\Enums\SponsorshipPlacement;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Models\Tag;
use App\Services\Sponsorship\SponsorshipSelector;
use Carbon\CarbonImmutable;

it('preferisce una campagna pertinente a categoria tag o giorno anche con priorità inferiore', function (string $filter): void {
    $city = testCity();
    freezeLocal($city, '2026-09-10 12:00');
    $cinema = testCategory(['name' => 'Cinema']);
    $music = testCategory(['name' => 'Musica']);
    $relevant = occurrenceAtLocal($city, $cinema, '2026-09-11 21:30');
    $generic = occurrenceAtLocal($city, $music, '2026-09-10 21:30');
    $tag = Tag::factory()->create();
    $relevant->event->tags()->attach($tag);
    $campaign = Sponsorship::factory()->create(['city_id' => $city->id, 'event_id' => $relevant->event_id, 'placement' => SponsorshipPlacement::ListTop, 'priority' => 1]);
    Sponsorship::factory()->create(['city_id' => $city->id, 'event_id' => $generic->event_id, 'placement' => SponsorshipPlacement::ListTop, 'priority' => 100]);
    $filters = EventFilters::fromArray(match ($filter) {
        'category' => ['category' => $cinema->slug],
        'tag' => ['tag' => $tag->slug],
        'date' => ['date' => 'tomorrow'],
    });

    expect(app(SponsorshipSelector::class)->first($city, SponsorshipPlacement::ListTop, filters: $filters)?->id)->toBe($campaign->id);
})->with(['category', 'tag', 'date']);

it('mantiene il fallback generale se nessuna campagna corrisponde e non inventa annunci', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-10 12:00');
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-11 21:30');
    $campaign = Sponsorship::factory()->create(['city_id' => $city->id, 'event_id' => $date->event_id, 'placement' => SponsorshipPlacement::ListTop]);
    $filters = EventFilters::fromArray(['category' => 'categoria-senza-campagne']);
    $selector = app(SponsorshipSelector::class);
    expect($selector->first($city, SponsorshipPlacement::ListTop, filters: $filters)?->id)->toBe($campaign->id);
    $campaign->update(['ends_at' => now()->subMinute()]);
    expect($selector->first($city, SponsorshipPlacement::ListTop, filters: $filters))->toBeNull();
});

it('mostra nella sponsorizzazione la replica del giorno selezionato', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-10 12:00');
    $today = occurrenceAtLocal($city, testCategory(), '2026-09-10 21:30');
    $tomorrow = EventOccurrence::factory()->startingAt(CarbonImmutable::parse('2026-09-11 19:30', 'UTC'))
        ->create(['event_id' => $today->event_id]);
    Sponsorship::factory()->create(['city_id' => $city->id, 'event_id' => $today->event_id, 'placement' => SponsorshipPlacement::ListTop]);

    $this->get('/eventi/domani')->assertOk()
        ->assertViewHas('sponsoredOccurrence', fn ($date): bool => $date->id === $tomorrow->id);
});
