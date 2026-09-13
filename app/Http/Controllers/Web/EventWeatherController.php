<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Queries\EventOccurrenceQuery;
use App\Services\Weather\EventWeather;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

final class EventWeatherController extends Controller
{
    use InteractsWithCity;

    public function __invoke(int $occurrence, EventWeather $weather): JsonResponse
    {
        $date = EventOccurrenceQuery::for($this->city())->forOccurrence($occurrence)->get()->first();
        abort_if($date === null, 404);

        return ApiResponse::item($weather->forOccurrence($date))->header('Cache-Control', 'public, max-age=300');
    }
}
