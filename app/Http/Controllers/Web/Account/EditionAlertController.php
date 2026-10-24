<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Services\Events\EditionAlerts;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Gli avvisi «prossima edizione», dal sito (`/avvisi-edizione`) e dall'API. */
final class EditionAlertController extends Controller
{
    public function index(Request $request, EditionAlerts $alerts): JsonResponse
    {
        return ApiResponse::collection($alerts->ids($this->user($request)));
    }

    public function store(Request $request, int $event, EditionAlerts $alerts): RedirectResponse|JsonResponse
    {
        $model = Event::query()->whereKey($event)->whereIn('status', [EventStatus::Published, EventStatus::Archived])->firstOrFail();
        $alerts->subscribe($this->user($request), $model);

        return $request->is('api/*')
            ? ApiResponse::item(['event_id' => (int) $model->getKey()], status: 201)
            : back()->with('status', __('events.edition_alert.stored'));
    }

    public function destroy(Request $request, int $event, EditionAlerts $alerts): RedirectResponse|JsonResponse
    {
        $alerts->unsubscribe($this->user($request), $event);

        return $request->is('api/*') ? response()->json(null, 204) : back()->with('status', __('events.edition_alert.removed'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
