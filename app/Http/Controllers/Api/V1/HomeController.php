<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\SponsorshipPlacement;
use App\Enums\TimeOfDay;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EventQueryRequest;
use App\Http\Resources\V1\OccurrenceResource;
use App\Http\Resources\V1\SponsorshipResource;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Models\User;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Services\Api\OccurrenceFeed;
use App\Services\Sponsorship\SponsorshipSelector;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

final class HomeController extends Controller
{
    use InteractsWithApi;

    public function __construct(
        private readonly OccurrenceFeed $feed,
        private readonly SponsorshipSelector $sponsorships,
    ) {}

    public function __invoke(EventQueryRequest $request): JsonResponse
    {
        $city = $this->city();
        $user = $this->currentUser($request);
        $size = config()->integer('eventi.home_section_size');

        $queries = [
            'ongoing' => EventOccurrenceQuery::for($city)->ongoing(),
            'starting_soon' => EventOccurrenceQuery::for($city)->startingSoon(),
            'tonight' => EventOccurrenceQuery::for($city)->tonight(),
            'today' => EventOccurrenceQuery::for($city)->today()->timeOfDay(TimeOfDay::Day),
            'featured' => EventOccurrenceQuery::for($city)->upcoming()->featured()->orderByRelevance(),
            'weekend' => EventOccurrenceQuery::for($city)->weekend(),
            'nearby' => EventOccurrenceQuery::for($city)
                ->upcoming()
                ->near((float) $city->center_lat, (float) $city->center_lng, config()->float('eventi.nearby_radius_km'))
                ->orderByDistance(),
        ];

        $sections = [];

        foreach ($queries as $name => $query) {
            $items = $query->get()->unique('event_id')->take($size)->values();
            $this->feed->hydrate($items, $request->includes());
            $context = ApiContext::forOccurrences($city, $request->includes(), $user, $this->feed->ids($items));
            $sections[$name] = $items
                ->map(static fn (EventOccurrence $occurrence): array => OccurrenceResource::toArray($occurrence, $context))
                ->all();
        }

        return ApiResponse::item([
            'city' => (string) $city->slug,
            'sections' => $sections,
            'sponsorships' => [
                'hero' => $this->sponsorship($city, SponsorshipPlacement::HomeHero, $user),
                'card' => $this->sponsorship($city, SponsorshipPlacement::HomeCard, $user),
            ],
            'map' => [
                'markers' => EventOccurrenceQuery::for($city)
                    ->upcoming()
                    ->occurrencePoints(config()->integer('api.limits.map_default')),
            ],
            'stats' => [
                'upcoming' => EventOccurrenceQuery::for($city)->upcoming()->count(),
                'week' => EventOccurrenceQuery::for($city)->nextDays(7)->count(),
                'venues' => Venue::query()->inCity($city)->approved()->count(),
                'categories' => count(EventOccurrenceQuery::for($city)->upcoming()->countsByCategory()),
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function sponsorship(City $city, SponsorshipPlacement $placement, ?User $user): ?array
    {
        $sponsorship = $this->sponsorships->first($city, $placement, user: $user);

        if (! $sponsorship instanceof Sponsorship) {
            return null;
        }

        $occurrence = EventOccurrenceQuery::for($city)
            ->forEvent((int) $sponsorship->event_id)
            ->upcoming()
            ->get()
            ->first();
        $items = $occurrence instanceof EventOccurrence ? new Collection([$occurrence]) : new Collection;
        $this->feed->hydrate($items, []);
        $context = ApiContext::forOccurrences($city, [], $user, $this->feed->ids($items));

        return SponsorshipResource::toArray($sponsorship, $occurrence, $context);
    }
}
