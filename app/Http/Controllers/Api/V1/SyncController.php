<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Enums\VenueStatus;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncRequest;
use App\Http\Resources\V1\OccurrenceResource;
use App\Models\EventOccurrence;
use App\Queries\EventOccurrenceQuery;
use App\Services\Api\OccurrenceFeed;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

final class SyncController extends Controller
{
    use InteractsWithApi;

    public function __construct(private readonly OccurrenceFeed $feed) {}

    public function __invoke(SyncRequest $request): JsonResponse
    {
        $city = $this->city();
        $serverTime = CarbonImmutable::now('UTC');
        $query = EventOccurrenceQuery::for($city)->nextDays($request->days());

        if ($request->since() !== null) {
            $query->contentUpdatedSince($request->since());
        }

        $page = $this->feed->page($city, $request, $this->currentUser($request), $query);

        return ApiResponse::page(
            $page->paginator,
            static fn (EventOccurrence $occurrence): array => OccurrenceResource::toArray($occurrence, $page->context),
            [
                'server_time' => $serverTime->toIso8601String(),
                'deleted_ids' => $this->deletedIds($request),
                'window_days' => $request->days(),
            ],
        );
    }

    /** @return list<int> */
    private function deletedIds(SyncRequest $request): array
    {
        $since = $request->since();

        if ($since === null) {
            return [];
        }

        $city = $this->city();
        $instant = $since->utc()->format('Y-m-d H:i:s');

        $candidates = EventOccurrence::withTrashed()
            ->join('events', 'events.id', '=', 'event_occurrences.event_id')
            ->leftJoin('venues', 'venues.id', '=', 'events.venue_id')
            ->where('events.city_id', $city->getKey())
            ->where(function (Builder $changed) use ($instant): void {
                $changed->where('event_occurrences.updated_at', '>=', $instant)
                    ->orWhere('event_occurrences.deleted_at', '>=', $instant)
                    ->orWhere('events.updated_at', '>=', $instant)
                    ->orWhere('events.deleted_at', '>=', $instant)
                    ->orWhere('venues.updated_at', '>=', $instant)
                    ->orWhere('venues.deleted_at', '>=', $instant);
            })
            ->where(function (Builder $hidden): void {
                $hidden->whereNotNull('event_occurrences.deleted_at')
                    ->orWhereNotNull('events.deleted_at')
                    ->orWhere('events.status', '!=', EventStatus::Published->value)
                    ->orWhere(function (Builder $venue): void {
                        $venue->whereNotNull('events.venue_id')
                            ->where(function (Builder $invalid): void {
                                $invalid->whereNotNull('venues.deleted_at')
                                    ->orWhereNotIn('venues.status', VenueStatus::valoriSenzaProvvedimento());
                            });
                    });
            })
            ->distinct()
            ->pluck('event_occurrences.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return $candidates;
    }
}
