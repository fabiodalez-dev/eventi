<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\DTOs\NotificationPreferences;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithMe;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\UpdateNotificationPreferencesRequest;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET/PATCH /v1/me/notification-preferences` (§15.4).
 *
 * La risposta porta **anche** ciò che non si può cambiare: `cancellations`
 * vale sempre `true` ed è dichiarato perché un'app non deve disegnare un
 * interruttore che il server ignorerebbe. Insieme viaggiano le due cose che
 * governano il volume (§15.4): l'orario del digest e le ore di silenzio.
 */
final class NotificationPreferenceController extends Controller
{
    use InteractsWithMe;

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::item($this->payload($request));
    }

    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $preferences = NotificationPreferences::fromUser($user)->with($request->validated());

        $user->notification_preferences = $preferences->toArray();
        $user->save();

        return ApiResponse::item($this->payload($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $user = $this->user($request);

        return [
            ...$user->notificationPreferences()->toArray(),

            /*
             * §15.4: «attivo, non disattivabile». Un promemoria per una serata
             * annullata è peggio dell'assenza di promemoria.
             */
            'cancellations' => true,
            'daily_digest_time' => $user->daily_digest_time,
            'quiet_hours' => $user->quiet_hours,
            'marketing_opt_in' => $user->marketing_opt_in_at !== null,
        ];
    }
}
