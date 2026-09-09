<?php

namespace App\Services\Search;

use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Services\Seo\EditorialContent;
use Illuminate\Database\Eloquent\Collection;

final class TonightDiscovery
{
    /** @param array<string, mixed> $input
     * @return Collection<int, EventOccurrence>
     */
    public function find(City $city, array $input): Collection
    {
        return $this->query($city, $input)->firstPerEvent(5)->load(['event.category', 'event.media', 'event.venue', 'venue']);
    }

    /** @param array<string, mixed> $input */
    private function query(City $city, array $input): EventOccurrenceQuery
    {
        $query = EventOccurrenceQuery::for($city);
        ($input['when'] ?? 'tonight') === 'starting_soon' ? $query->startingSoon() : $query->tonight();
        $query->upcoming()->ended(false)->availableForDiscovery();
        if (filled($input['municipality'] ?? null)) {
            $query->inMunicipality($input['municipality']);
        }
        if ((! array_key_exists('municipality', $input) || $input['municipality'] === 'Padova') && filled($input['zone'] ?? null)) {
            $query->inZone($input['zone']);
        }
        if (filled($input['budget'] ?? null)) {
            $query->discoveryBudget((int) $input['budget']);
        }
        if (! empty($input['categories'])) {
            $query->inCategories(array_map('intval', $input['categories']));
        }

        return $query;
    }

    /**
     * Place facets replace the current place, retaining time, budget and categories.
     *
     * @param  array<string, mixed>  $input
     * @return array{total:int, everywhere:int, municipalities:array<string,int>, zones:array<string,int>}
     */
    public function counts(City $city, array $input): array
    {
        $base = [...$input, 'municipality' => '', 'zone' => ''];
        $byVenue = $this->query($city, $base)->countsByVenue();
        $municipalities = [];
        $zones = [];
        foreach (Venue::query()->whereIn('id', array_keys($byVenue))->get(['id', 'municipality', 'zone']) as $venue) {
            $name = (string) $venue->municipality;
            $municipalities[$name] = ($municipalities[$name] ?? 0) + $byVenue[$venue->id];
            if ($name === 'Padova' && filled($venue->zone)) {
                $zones[$venue->zone] = ($zones[$venue->zone] ?? 0) + $byVenue[$venue->id];
            }
        }

        return ['total' => $this->query($city, $input)->count(), 'everywhere' => $this->query($city, $base)->count(), 'municipalities' => $municipalities, 'zones' => $zones];
    }

    /** @param array<string, mixed> $input
     * @return list<string>
     */
    public function reasons(EventOccurrence $date, array $input): array
    {
        $reasons = [__('tonight.reason_time')];
        if (filled($input['municipality'] ?? null)) {
            $reasons[] = __('tonight.reason_municipality', ['municipality' => $date->effectiveVenue()?->municipality]);
        }
        if ((! array_key_exists('municipality', $input) || $input['municipality'] === 'Padova') && filled($input['zone'] ?? null)) {
            $reasons[] = __('tonight.reason_zone', ['zone' => $date->effectiveVenue()?->zone]);
        }
        if (filled($input['budget'] ?? null)) {
            $reasons[] = __('tonight.reason_budget');
        }
        if (! empty($input['categories'])) {
            $reasons[] = __('tonight.reason_category', ['category' => $date->event->category?->name]);
        }

        return $reasons;
    }

    /** @return list<array{label: string, value: string}> */
    public function practical(EventOccurrence $date): array
    {
        $details = app(EditorialContent::class)->details($date->event, $date);
        $venue = $date->effectiveVenue();
        if (blank($details['membership_notes'] ?? null) && $venue?->requires_membership) {
            $details['membership_notes'] = __('seo.yes').(filled($venue->membership_notes) ? ': '.$venue->membership_notes : '');
        }
        if (blank($details['parking_notes'] ?? null) && in_array($details['parking_type'] ?? null, ['free', 'paid', 'none'], true)) {
            $details['parking_notes'] = __('seo.parking_'.$details['parking_type']);
        }
        $rows = [];
        foreach (['mandatory_costs', 'membership_notes', 'entrance_notes', 'transit_notes', 'parking_notes', 'weather_policy'] as $key) {
            $rows[] = ['label' => __('seo.fields.'.$key), 'value' => filled($details[$key] ?? null) ? (string) $details[$key] : __('tonight.unknown')];
        }
        $rows[] = ['label' => __('seo.fields.accessibility'), 'value' => match ($details['accessibility'] ?? null) {
            'yes' => __('seo.yes'), 'no' => __('seo.no'), default => __('tonight.unknown')
        }];
        $rows[] = ['label' => __('seo.minimum_age'), 'value' => isset($details['minimum_age']) ? (string) $details['minimum_age'] : __('tonight.unknown')];

        return $rows;
    }
}
