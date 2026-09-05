<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SponsorshipRequest;
use App\Http\Resources\V1\SponsorshipResource;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Queries\EventOccurrenceQuery;
use App\Services\Api\OccurrenceFeed;
use App\Services\Sponsorship\SponsorshipSelector;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

final class SponsorshipController extends Controller
{
    use InteractsWithApi;

    public function __construct(
        private readonly SponsorshipSelector $selector,
        private readonly OccurrenceFeed $feed,
    ) {}

    public function __invoke(SponsorshipRequest $request): JsonResponse
    {
        $city = $this->city();
        $user = $this->currentUser($request);
        $rows = $this->selector->forPlacement($city, $request->placement(), user: $user);

        return ApiResponse::collection($rows->map(function (Sponsorship $sponsorship) use ($city, $user): array {
            $occurrence = EventOccurrenceQuery::for($city)
                ->forEvent((int) $sponsorship->event_id)
                ->upcoming()
                ->get()
                ->first();

            $items = $occurrence instanceof EventOccurrence ? new Collection([$occurrence]) : new Collection;
            $this->feed->hydrate($items, []);
            $context = ApiContext::forOccurrences($city, [], $user, $this->feed->ids($items));

            return SponsorshipResource::toArray($sponsorship, $occurrence, $context);
        })->all());
    }
}
