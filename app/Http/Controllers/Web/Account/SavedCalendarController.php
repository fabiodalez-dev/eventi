<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithAccount;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Services\Calendar\SavedCalendar;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SavedCalendarController extends Controller
{
    use InteractsWithAccount;
    use InteractsWithCity;

    public function download(Request $request, SavedCalendar $calendar): Response
    {
        return response($calendar->export($this->city(), $this->accountUser($request)), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="incitta-salvati.ics"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function api(Request $request, SavedCalendar $calendar): JsonResponse
    {
        return ApiResponse::item(['ics' => $calendar->export($this->city(), $this->accountUser($request))])
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
