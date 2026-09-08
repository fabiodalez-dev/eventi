<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\City;
use App\Models\Sponsorship;
use App\Queries\EventOccurrenceQuery;
use Illuminate\Database\Seeder;
use RuntimeException;

/** Explicit local preview fixtures. Never included in DatabaseSeeder or production deploys. */
class SponsorshipBannerDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('I banner di prova possono essere creati solo in locale.');
        }

        foreach (City::query()->active()->get() as $city) {
            $this->seedCity($city);
        }
    }

    /** @return list<int> */
    public function seedCity(City $city): array
    {
        $ids = [];
        $dates = EventOccurrenceQuery::for($city)->promotable()->get()->unique('event_id')->take(3);
        foreach ($dates->values() as $index => $date) {
            $campaign = Sponsorship::updateOrCreate([
                'city_id' => $city->id,
                'notes' => 'DEMO locale — banner di prova '.($index + 1),
            ], [
                'event_id' => $date->event_id,
                'placement' => 'list_top', 'status' => 'active',
                'starts_at' => now()->subMinute(), 'ends_at' => now()->addWeek(),
                'priority' => 0, 'weight' => 1,
                'advertiser_name' => $date->event->venue->name ?? 'inCittà',
                'amount_cents' => 0, 'currency' => 'EUR',
            ]);
            $ids[] = (int) $campaign->id;
        }

        return $ids;
    }
}
