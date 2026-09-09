<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\CalendarWizardRequest;
use App\Models\GoogleCalendarConnection;
use App\Services\Calendar\GoogleCalendarClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

final class GoogleCalendarController extends Controller
{
    public function manage(CalendarWizardRequest $request): JsonResponse
    {
        // This link does not authenticate anyone. The browser must log in as
        // the same inCittà user before starting Google's consent flow.
        return response()->json(['data' => ['url' => URL::temporarySignedRoute('google-calendar.mobile', now()->addMinutes(15), [
            ...$request->safe()->except('step'), 'user' => $request->user()->id,
        ])]])->header('Cache-Control', 'private, no-store');
    }

    public function __invoke(Request $request, GoogleCalendarClient $client): JsonResponse
    {
        $connection = GoogleCalendarConnection::where('user_id', $request->user()->id)->first();

        return response()->json(['data' => [
            'configured' => $client->configured(),
            'connected' => $connection->enabled ?? false,
            'synced_at' => $connection?->synced_at?->toIso8601String(),
            'event_count' => $connection->event_count ?? 0,
            'error_code' => $connection?->error_code?->value,
            'selection' => $connection?->selection,
        ]])->header('Cache-Control', 'private, no-store');
    }
}
