<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MapQueryRequest;
use App\Services\Api\OccurrenceFeed;
use App\Support\Api\ApiDate;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * `GET /v1/map/occurrences` (§13.3).
 *
 * Sette campi per marcatore e nient'altro: la mappa ne carica centinaia in
 * una volta, e la locandina, il prezzo e la descrizione arriverebbero per
 * migliaia di punti che nessuno toccherà mai. Il dettaglio si chiede dopo,
 * per il solo marcatore toccato, a `GET /v1/occurrences/{id}`.
 *
 * I filtri sono gli stessi della lista, rettangolo compreso, e passano dallo
 * stesso motore: i punti sulla mappa e le righe nella lista sono lo stesso
 * insieme di date.
 */
final class MapController extends Controller
{
    use InteractsWithApi;

    public function __construct(private readonly OccurrenceFeed $feed) {}

    public function __invoke(MapQueryRequest $request): JsonResponse
    {
        $city = $this->city();
        $limit = $request->limit();

        $points = $this->feed->query($city, $request)->occurrencePoints($limit + 1);

        $truncated = count($points) > $limit;
        $points = array_slice($points, 0, $limit);

        $timezone = $city->timezone;

        $markers = array_map(
            static fn (array $point): array => [
                'id' => $point['id'],
                'event_id' => $point['event_id'],
                'lat' => round($point['lat'], 6),
                'lng' => round($point['lng'], 6),
                'category_id' => $point['category_id'],
                'title' => $point['title'],
                'starts_at' => ApiDate::instant(CarbonImmutable::parse($point['starts_at'], 'UTC'), $timezone),
            ],
            $points,
        );

        return ApiResponse::collection($markers, ['truncated' => $truncated]);
    }
}
