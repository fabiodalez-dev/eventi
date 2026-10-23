<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Services\Ticketing\AvailabilityAlerts;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Gli avvisi «tornano disponibili», dal sito (`/avvisi-disponibilita`) e dall'API. */
final class AvailabilityAlertController extends Controller
{
    public function index(Request $request, AvailabilityAlerts $alerts): JsonResponse
    {
        return ApiResponse::collection($alerts->ids($this->user($request)));
    }

    public function store(Request $request, EventOccurrence $occurrence, AvailabilityAlerts $alerts): RedirectResponse|JsonResponse
    {
        $occurrence->loadMissing('event');
        abort_unless($occurrence->event?->status === EventStatus::Published, 404);
        $alerts->subscribe($this->user($request), $occurrence);

        return $request->is('api/*')
            ? ApiResponse::item(['occurrence_id' => (int) $occurrence->getKey()], status: 201)
            : back()->with('status', __('ticketing.availability.stored'));
    }

    public function destroy(Request $request, int $occurrence, AvailabilityAlerts $alerts): RedirectResponse|JsonResponse
    {
        $alerts->unsubscribe($this->user($request), $occurrence);

        return $request->is('api/*') ? response()->json(null, 204) : back()->with('status', __('ticketing.availability.removed'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
