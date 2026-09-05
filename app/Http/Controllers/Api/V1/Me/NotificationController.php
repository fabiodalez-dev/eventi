<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithMe;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\MeQueryRequest;
use App\Http\Resources\V1\NotificationResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * `GET /v1/me/notifications` (§15.8): l'archivio in-app.
 *
 * È il terzo canale di §15.6 — «sempre in-app come archivio consultabile» — e
 * l'unico che non si sceglie: c'è qualunque sia la strada presa in fondo alla
 * catena, push o email (D54). Un promemoria letto di sfuggita e chiuso si
 * ritrova qui.
 */
final class NotificationController extends Controller
{
    use InteractsWithMe;

    public function __invoke(MeQueryRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $timezone = (string) $user->timezone;

        $paginator = $user->notifications()
            ->cursorPaginate($request->limit(), cursor: $request->cursor())
            ->withQueryString();

        return ApiResponse::page(
            $paginator,
            static fn (DatabaseNotification $notification): array => NotificationResource::toArray($notification, $timezone),
            ['unread_count' => $user->unreadNotifications()->count()],
        );
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $user = $this->user($request);
        $row = $user->notifications()->whereKey($notification)->first();

        if (! $row instanceof DatabaseNotification) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        $row->markAsRead();

        return ApiResponse::item(NotificationResource::toArray($row->refresh(), (string) $user->timezone));
    }

    public function readAll(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $updated = $user->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::item([
            'updated' => $updated,
            'unread_count' => 0,
            'message' => __('account.api.notifications_read'),
        ]);
    }
}
