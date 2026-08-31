<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\DTOs\NotificationPreferences;
use App\DTOs\QuietHours;
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

            /*
             * Due campi e non uno (D36). `quiet_hours` è **la scelta**: gli
             * orari se ne sono stati scritti, un oggetto vuoto se la persona
             * ha rifiutato il silenzio, `null` se non ha ancora deciso.
             * `quiet_hours_effective` è ciò che il motore applica davvero, e
             * per chi non ha deciso è la finestra predefinita.
             *
             * Senza il secondo, un'applicazione che mostra il primo direbbe
             * «nessuna ora di silenzio» a chi le ha eccome, e l'unico modo di
             * accorgersene sarebbe un promemoria che non arriva.
             */
            'quiet_hours' => $user->quiet_hours,
            'quiet_hours_effective' => QuietHours::fromUser($user)?->toArray(),
            'marketing_opt_in' => $user->marketing_opt_in_at !== null,
        ];
    }
}
