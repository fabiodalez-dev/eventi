<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\DTOs\EventFilters;
use App\Enums\AccessibilityFeature;
use App\Enums\DatePreset;
use App\Enums\PriceFilter;
use App\Enums\TimeOfDay;
use App\Models\Category;
use App\Models\City;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;

/** Counts intersect ALL current filters, never just the first result page. */
final class ContextualFacets
{
    /** @param null|\Closure(): EventOccurrenceQuery $factory
     * @return array<string, array<string, int>>
     */
    public function build(City $city, EventFilters $filters, ?\Closure $factory = null): array
    {
        $web = $factory === null;
        $factory ??= fn () => app(EventFinder::class)->query($city, $filters);
        $result = array_fill_keys(['category', 'tag', 'municipality', 'zone', 'venue', 'date', 'price', 'time', 'features', 'access'], []);
        if ($web && $filters->hasPosition()) {
            foreach (config()->array('eventi.distance_options') as $km) {
                $result['radius'][(string) $km] = app(EventFinder::class)
                    ->query($city, $filters->withPosition($filters->lat, $filters->lng, (float) $km))->count();
            }
        }
        $categoryCounts = $factory()->countsByCategory();
        foreach (Category::query()->whereIn('id', array_keys($categoryCounts))->get(['id', 'slug']) as $category) {
            $result['category'][$category->slug] = $categoryCounts[$category->id];
        }
        $venueCounts = $factory()->countsByVenue();
        foreach (Venue::query()->whereIn('id', array_keys($venueCounts))->get(['id', 'slug', 'municipality', 'zone']) as $venue) {
            $count = $venueCounts[$venue->id];
            $result['venue'][$venue->slug] = $count;
            foreach (['municipality', 'zone'] as $key) {
                if (filled($venue->{$key})) {
                    $result[$key][$venue->{$key}] = ($result[$key][$venue->{$key}] ?? 0) + $count;
                }
            }
        }
        foreach (app(FilterFacets::class)->tags() as $tag) {
            $result['tag'][$tag->slug] = $factory()->withTags([$tag->slug])->count();
        }
        foreach (DatePreset::cases() as $value) {
            $query = $factory();
            $value->applyTo($query);
            $result['date'][$value->value] = $query->count();
        }
        foreach (PriceFilter::cases() as $value) {
            $query = $factory();
            $value->applyTo($query);
            $result['price'][$value->value] = $query->count();
        }
        foreach (TimeOfDay::cases() as $value) {
            $result['time'][$value->value] = $factory()->timeOfDay($value)->count();
        }
        $result['features']['outdoor'] = $factory()->outdoor()->count();
        $result['features']['accessible'] = $factory()->accessible()->count();
        $result['features']['family'] = $factory()->inCategories(config()->array('eventi.family_categories'))->count();
        foreach (AccessibilityFeature::cases() as $value) {
            $result['access'][$value->value] = $factory()->hasAccessibilityFeature($value)->count();
        }

        return $result;
    }
}
