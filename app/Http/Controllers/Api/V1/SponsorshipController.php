<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SponsorshipRequest;
use App\Http\Resources\V1\SponsorshipResource;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Models\User;
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

        return ApiResponse::collection($this->items($rows, $city, $user));
    }

    /**
     * Una voce per campagna, ognuna con il suo gettone nuovo.
     *
     * Un metodo a sé con il tipo dichiarato: è da qui che il documento
     * OpenAPI legge che `data` è un elenco, qualunque cosa diventi il
     * selettore.
     *
     * @param  iterable<Sponsorship>  $rows
     * @return list<array<string, mixed>>
     */
    private function items(iterable $rows, City $city, ?User $user): array
    {
        $items = [];

        foreach ($rows as $sponsorship) {
            $occurrence = EventOccurrenceQuery::for($city)
                ->forEvent((int) $sponsorship->event_id)
                ->upcoming()
                ->get()
                ->first();

            $dates = $occurrence instanceof EventOccurrence ? new Collection([$occurrence]) : new Collection;
            $this->feed->hydrate($dates, []);
            $context = ApiContext::forOccurrences($city, [], $user, $this->feed->ids($dates));

            $items[] = SponsorshipResource::toArray($sponsorship, $occurrence, $context);
        }

        return $items;
    }
}
