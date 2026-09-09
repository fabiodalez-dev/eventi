<?php

use App\DTOs\EventFilters;
use App\Enums\DatePreset;
use App\Enums\EventSort;
use App\Models\Venue;
use App\Services\Search\ContextualFacets;
use MatanYadaev\EloquentSpatial\Objects\Point;

beforeEach(function () {
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-10 12:00');
    $this->category = testCategory(['slug' => 'teatro-e-danza']);
    $venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->id, 'lat' => 45.55, 'lng' => 11.87,
        'location' => new Point(45.55, 11.87, 0),
        'accessibility' => ['step_free_entrance' => true],
    ]);
    occurrenceAtLocal($this->city, $this->category, '2026-09-11 21:00', venue: $venue);
});

function distanceLinks(string $html): array
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($dom);

    return iterator_to_array($xpath->query('//section[@aria-labelledby="vicino-a-me"]//a'));
}

it('the active radius cross clears coordinates radius and distance ordering only', function (string $route, int $radius) {
    $query = ['date' => 'tomorrow', 'category' => 'teatro-e-danza', 'time' => 'evening', 'accessible' => 1, 'lat' => 45.4, 'lng' => 11.87, 'radius' => $radius, 'sort' => 'distance'];
    $html = $this->get($route.'?'.http_build_query($query))->assertOk()->getContent();
    $active = array_values(array_filter(distanceLinks($html), fn ($link) => $link->getAttribute('aria-current') === 'true'));
    expect($active)->toHaveCount(1);
    parse_str(parse_url($active[0]->getAttribute('href'), PHP_URL_QUERY), $removed);
    expect($removed)->toBe(collect($query)->except(['lat', 'lng', 'radius', 'sort'])->map(fn ($v) => (string) $v)->all());
    expect($active[0]->hasAttribute('data-filter-link'))->toBeTrue();
    $this->get($route.'?'.http_build_query($removed))->assertOk()->assertViewHas('occurrences', fn ($rows) => $rows->count() === 1);
})->with(['/mappa', '/eventi'])->with([1, 5, 10, 25]);

it('distance counts replace the old radius and retain the other constraints', function (int $radius) {
    $filters = EventFilters::fromArray(['date' => 'tomorrow', 'category' => 'teatro-e-danza', 'lat' => 45.4, 'lng' => 11.87, 'radius' => $radius]);
    $counts = app(ContextualFacets::class)->build($this->city, $filters);
    expect($counts['radius'])->toBe([1 => 0, 5 => 0, 10 => 0, 25 => 1]);
    $wrongDate = $filters->withPreset(DatePreset::Today);
    expect(array_sum(app(ContextualFacets::class)->build($this->city, $wrongDate)['radius']))->toBe(0);
})->with([1, 5, 10, 25]);

it('keeps all dates when removing or changing distance', function (string $route) {
    $html = $this->get($route.'?all_dates=1&lat=45.4&lng=11.87&radius=1')->assertOk()->getContent();
    foreach (distanceLinks($html) as $link) {
        parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
        expect($query['all_dates'])->toBe('1');
    }
    expect($html)->toContain('data-result-count=');
})->with(['/mappa', '/eventi']);

it('never offers zero radius alternatives while leaving the selected radius removable', function (string $route) {
    $html = $this->get($route.'?date=tomorrow&lat=45.4&lng=11.87&radius=1')->assertOk()->getContent();
    foreach (distanceLinks($html) as $link) {
        parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
        if (isset($query['radius'])) {
            expect($query['radius'])->toBe('25');
        }
    }
})->with(['/mappa', '/eventi']);

it('removing position keeps unrelated ordering and counts one geographic filter', function (?EventSort $sort) {
    $filters = new EventFilters(lat: 45.4, lng: 11.87, radius: 25, sort: $sort);
    expect($filters->activeCount())->toBe(1);
    $removed = $filters->withPosition(null, null, null);
    expect($removed->hasPosition())->toBeFalse()->and($removed->activeCount())->toBe(0)
        ->and($removed->sort)->toBe($sort === EventSort::Distance ? null : $sort);
})->with([null, EventSort::Distance, EventSort::Time, EventSort::Relevance]);

it('every offered narrowing chip keeps at least one matching event', function (string $route) {
    $html = $this->get($route.'?date=tomorrow')->assertOk()->getContent();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $links = (new DOMXPath($dom))->query('//section[@data-filter-panel]//a[not(@aria-current)]');
    foreach ($links as $link) {
        $url = $link->getAttribute('href');
        if (! parse_url($url, PHP_URL_QUERY)) {
            continue;
        }
        $this->get($url)->assertOk()->assertViewHas('occurrences', fn ($rows) => $rows->count() > 0);
    }
})->with(['/mappa', '/eventi']);
