<?php

declare(strict_types=1);

use App\DTOs\EventFilters;
use App\Models\Venue;
use App\Services\Search\EventFinder;
use App\Services\Seo\EditorialContent;
use App\Services\Weather\EventWeather;
use App\Support\BeforeGoingDefaults;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-13 12:00');
});

it('inherits age and facilities and applies event exceptions in web and API filters', function (): void {
    $date = occurrenceAtLocal($this->city, $this->category, '2026-09-15 18:00');
    $venue = $date->event->venue;
    $venue->update(['content_details' => ['age_groups' => ['3-5', '6-10'], 'stroller' => 'yes', 'changing_table' => 'yes', 'kids_area' => 'no']]);
    $details = app(EditorialContent::class)->details($date->event->fresh(), $date->fresh());
    expect($details['age_groups'])->toBe(['3-5', '6-10'])->and(collect($details['practical_items'])->pluck('label')->all())->toContain(__('family.title'), __('family.changing_table').': '.__('family.yes'));
    $filters = EventFilters::fromArray(['age' => '3-5', 'stroller' => '1']);
    expect(app(EventFinder::class)->query($this->city, $filters)->get()->modelKeys())->toContain($date->id);
    $this->getJson('/api/v1/events?age=3-5&stroller=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/events?age=11-17')->assertOk()->assertJsonCount(0, 'data');
    $date->event->update(['content_details' => ['age_groups' => ['18-plus'], 'stroller' => 'no']]);
    expect(app(EventFinder::class)->query($this->city, $filters)->get())->toBeEmpty();
    $this->get('/eventi?age=3-5&stroller=1')->assertOk();
    expect(BeforeGoingDefaults::override('stroller', 'yes', 'yes'))->toBeNull();
});

it('preserves the family filters when another filter changes', function (): void {
    $filters = EventFilters::fromArray(['age' => '6-10', 'changing_table' => '1', 'kids_area' => '1']);
    expect($filters->withFamily(true)->toQueryString())->toMatchArray(['age' => '6-10', 'changing_table' => '1', 'kids_area' => '1']);
    $this->getJson('/api/v1/events?age=invalid')->assertUnprocessable();
    $this->get('/eventi?age=invalid')->assertOk();
});

it('uses the effective venue and day and caches provider calls', function (): void {
    Http::fake(['api.open-meteo.com/*' => Http::response(['daily' => ['time' => ['2026-09-15'], 'weather_code' => [95], 'temperature_2m_min' => [12], 'temperature_2m_max' => [23], 'precipitation_probability_max' => [80], 'wind_speed_10m_max' => [18]]])]);
    $date = occurrenceAtLocal($this->city, $this->category, '2026-09-15 18:00');
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'lat' => 45.4, 'lng' => 11.9]);
    $date->update(['venue_id' => $venue->id]);
    $data = app(EventWeather::class)->forOccurrence($date->fresh());
    expect($data)->toMatchArray(['available' => true, 'date' => '2026-09-15', 'icon' => 'storm', 'rain_probability' => 80.0, 'indicative' => false]);
    app(EventWeather::class)->forOccurrence($date->fresh());
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['latitude'] === 45.4 && $request['longitude'] === 11.9 && $request['timezone'] === 'Europe/Rome' && $request['forecast_days'] === 16);
    $this->getJson('/api/v1/occurrences/'.$date->id.'/weather')->assertOk()->assertJsonPath('data.icon', 'storm');
});

it('does not invent forecasts for past distant missing or failed forecasts', function (): void {
    Http::fake(['*' => Http::response([], 503)]);
    $service = app(EventWeather::class);
    foreach (['2026-09-12 18:00', '2026-09-29 18:00'] as $time) {
        $date = occurrenceAtLocal($this->city, $this->category, $time);
        expect($service->forOccurrence($date)['available'])->toBeFalse();
    }
    Http::assertNothingSent();
    $date = occurrenceAtLocal($this->city, $this->category, '2026-09-15 18:00');
    expect($service->forOccurrence($date)['available'])->toBeFalse();
    $this->getJson('/api/v1/occurrences/999999/weather')->assertNotFound();
});
