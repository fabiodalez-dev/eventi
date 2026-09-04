<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithMe;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\StoreDeviceRequest;
use App\Http\Resources\V1\DeviceResource;
use App\Models\Device;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST/DELETE /v1/me/devices` (§15.8).
 *
 * Registrare un dispositivo è ciò che accende il canale push per chi lo usa:
 * §15.6 sceglie guardando quale dispositivo è stato attivo negli ultimi trenta
 * giorni, e quella data si aggiorna solo se qualcuno la scrive. Da D54 un
 * dispositivo `web` con `endpoint` e `keys` riceve davvero le notifiche —
 * `App\Models\WebPushSubscription` legge da questa stessa tabella. Quando
 * arriverà FCM (F11) cambierà il canale, non questa chiamata.
 *
 * Il browser del **sito** non passa da qui ma da `POST /notifiche/push`:
 * questa rotta è dietro `auth:sanctum` senza `statefulApi()`, quindi da una
 * pagina a sessione risponderebbe 401. Vedi `PushSubscriptionController`.
 *
 * La seconda registrazione dello stesso riferimento **aggiorna** la riga
 * invece di crearne un'altra: `SCHEMA.md` §3.14 rinuncia di proposito a un
 * indice unico su colonne da 512 caratteri e mette la deduplica qui.
 */
final class DeviceController extends Controller
{
    use InteractsWithMe;

    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validated();

        $pushToken = is_string($data['push_token'] ?? null) ? $data['push_token'] : null;
        $endpoint = is_string($data['endpoint'] ?? null) ? $data['endpoint'] : null;

        $device = $user->devices()
            ->where(static function (Builder $query) use ($pushToken, $endpoint): void {
                if ($pushToken !== null) {
                    $query->orWhere('push_token', $pushToken);
                }

                if ($endpoint !== null) {
                    $query->orWhere('endpoint', $endpoint);
                }
            })
            ->first();

        $device ??= new Device(['user_id' => $user->getKey()]);

        $device->fill([
            'user_id' => $user->getKey(),
            'platform' => $request->platform(),
            'push_token' => $pushToken,
            'endpoint' => $endpoint,
            'keys' => $data['keys'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'locale' => $data['locale'] ?? null,
            'last_seen_at' => CarbonImmutable::now(),
            'revoked_at' => null,
        ])->save();

        return ApiResponse::item(
            DeviceResource::toArray($device, (string) $user->timezone),
            status: 201,
        );
    }

    /**
     * Un dispositivo non si cancella, si **revoca**: la riga resta perché
     * `revoked_at` è ciò che dice a §15.6 di escluderlo e di ripiegare
     * sull'email al prossimo invio. Cancellarla farebbe ricomparire lo stesso
     * dispositivo alla prima registrazione automatica dell'app.
     */
    public function destroy(Request $request, int $device): JsonResponse
    {
        $row = $this->user($request)->devices()->whereKey($device)->first();

        if (! $row instanceof Device) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        $row->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

        return ApiResponse::item(['message' => __('account.api.device_revoked')]);
    }
}
