<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\System\ScheduledNotificationListRequest;
use App\Models\ScheduledNotification;
use App\Services\Editorial\EditorialScheduledNotifications;
use App\Services\Editorial\ModerationDesk;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Gli invii previsti (R-SY-05, R-SY-06), come la risorsa «Notifiche
 * programmate» del pannello. Li guarda tutta la redazione
 * (`notifications.view`, anche il moderatore); fermarne uno in attesa è di
 * admin e super admin (`notifications.manage`). Fermare non cancella: la
 * riga resta con lo stato «annullata», come nel pannello, e non chiede la
 * password recente (nel pannello chiede solo una conferma).
 */
final class ScheduledNotificationController extends Controller
{
    public function index(ScheduledNotificationListRequest $request, EditorialScheduledNotifications $notifications): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        Gate::forUser($user)->authorize('viewAny', ScheduledNotification::class);

        $page = $notifications->notifications([
            'status' => $request->filterValue('status'),
            'type' => $request->filterValue('type'),
            'due' => $request->filterFlag('due'),
        ], $request->search(), $request->sortField(), $request->perPage(), $request->pageNumber());

        return response()->json([
            'data' => $page->getCollection()->map(fn (ScheduledNotification $notification): array => $notifications->row($notification, $user))->values(),
            'meta' => ModerationDesk::meta($page),
        ]);
    }

    /** Ferma un invio ancora in attesa; uno già partito, saltato o fermato è un 403 (la policy guarda lo stato). */
    public function cancel(int $notification, EditorialScheduledNotifications $notifications): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $found = $notifications->notification($notification);
        Gate::forUser($user)->authorize('cancel', $found);
        $found->markCancelled();

        return response()->json([
            'data' => $notifications->row($notifications->notification($found->id), $user),
            'meta' => ['message' => __('admin.notifications.notification_cancelled')],
        ]);
    }
}
