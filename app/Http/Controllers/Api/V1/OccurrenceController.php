<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EventQueryRequest;
use App\Http\Resources\V1\OccurrenceResource;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Queries\EventOccurrenceQuery;
use App\Services\Api\OccurrenceFeed;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * Una singola data (§13.1, `GET /v1/occurrences/{id}`).
 *
 * La si chiede **al motore** e non al modello: la base della query è già
 * ristretta agli eventi pubblicati e non cestinati della città, quindi
 * passare di lì è anche il modo di verificare che quella data sia davvero
 * visibile al pubblico. Una bozza risponde 404, non 200.
 */
final class OccurrenceController extends Controller
{
    use InteractsWithApi;

    public function __construct(private readonly OccurrenceFeed $feed) {}

    public function byNumber(EventQueryRequest $request, string $slug, int $number): JsonResponse
    {
        $event = Event::query()->inCity($this->city())->readable()->where('slug', $slug)->firstOrFail();
        $found = $event->occurrences()->where('url_number', $number)
            ->firstOrFail();

        return $this->show($request, (int) $found->id);
    }

    public function show(EventQueryRequest $request, int $occurrence): JsonResponse
    {
        $city = $this->city();

        $found = EventOccurrenceQuery::for($city)->forOccurrence($occurrence)->get()->first();

        if (! $found instanceof EventOccurrence) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        $includes = $request->includes();

        /** @var Collection<int, EventOccurrence> $items */
        $items = new Collection([$found]);
        $this->feed->hydrate($items, $includes);

        $context = ApiContext::forOccurrences($city, $includes, $this->currentUser($request), [(int) $found->getKey()]);

        return ApiResponse::item(OccurrenceResource::toArray($found, $context));
    }
}
