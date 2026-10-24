<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Services\Ticketing\BookingOpeningAlerts;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Gli avvisi «aprono le prenotazioni», dal sito (`/avvisi-apertura`) e dall'API. */
final class BookingOpeningAlertController extends Controller
{
    public function index(Request $request, BookingOpeningAlerts $alerts): JsonResponse
    {
        return ApiResponse::collection($alerts->ids($this->user($request)));
    }

    public function store(Request $request, EventOccurrence $occurrence, BookingOpeningAlerts $alerts): RedirectResponse|JsonResponse
    {
        $occurrence->loadMissing('event');
        abort_unless($occurrence->event?->status === EventStatus::Published, 404);
        $alerts->subscribe($this->user($request), $occurrence);

        return $request->is('api/*')
            ? ApiResponse::item(['occurrence_id' => (int) $occurrence->getKey()], status: 201)
            : back()->with('status', __('ticketing.opening.stored'));
    }

    public function destroy(Request $request, int $occurrence, BookingOpeningAlerts $alerts): RedirectResponse|JsonResponse
    {
        $alerts->unsubscribe($this->user($request), $occurrence);

        return $request->is('api/*') ? response()->json(null, 204) : back()->with('status', __('ticketing.opening.removed'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
