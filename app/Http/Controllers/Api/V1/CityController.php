<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CityResource;
use App\Models\City;
use App\Queries\EventOccurrenceQuery;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Le città accese (§13.1). Una città spenta non esiste per l'API: `is_active`
 * nasce falso di proposito, e una città si pubblica quando è pronta.
 */
final class CityController extends Controller
{
    public function index(): JsonResponse
    {
        $cities = City::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(static fn (City $city): array => CityResource::toArray($city))
            ->all();

        return ApiResponse::collection($cities);
    }

    public function show(string $slug): JsonResponse
    {
        $city = City::query()->active()->where('slug', $slug)->first();

        if (! $city instanceof City) {
            throw new ApiException(ApiErrorCode::CityNotFound);
        }

        return ApiResponse::item([
            ...CityResource::toArray($city),
            'upcoming_occurrences' => EventOccurrenceQuery::for($city)->upcoming()->count(),
        ]);
    }
}
