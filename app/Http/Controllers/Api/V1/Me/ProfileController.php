<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Actions\Account\DeleteAccount;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithMe;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\UpdateProfileRequest;
use App\Http\Resources\V1\UserResource;
use App\Support\Api\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET/PATCH/DELETE /v1/me` (§15.8).
 *
 * Il profilo è minimo per scelta (§15.2 e §16): nome facoltativo, email, fuso,
 * lingua. Ciò che non si raccoglie non si può perdere.
 */
final class ProfileController extends Controller
{
    use InteractsWithMe;

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::item(UserResource::toArray($this->user($request)));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validated();

        foreach (['name', 'timezone', 'locale', 'daily_digest_time', 'quiet_hours'] as $field) {
            if (array_key_exists($field, $data)) {
                $user->setAttribute($field, $data[$field]);
            }
        }

        /*
         * Il consenso marketing non è un campo come gli altri (§15.9): ciò che
         * conta è **quando** è stato dato. Toglierlo azzera la data, non
         * scrive un `false` da qualche parte.
         */
        if (array_key_exists('marketing_opt_in', $data)) {
            $user->marketing_opt_in_at = $request->boolean('marketing_opt_in')
                ? ($user->marketing_opt_in_at ?? Carbon::now())
                : null;
        }

        $user->save();

        return ApiResponse::item(UserResource::toArray($user->refresh()));
    }

    /**
     * Cancellazione self-service con effetto immediato (§15.2). Il token con
     * cui è arrivata la richiesta muore insieme all'account: la risposta
     * successiva della stessa app sarà un 401, che è la conferma più onesta
     * che si possa dare.
     */
    public function destroy(Request $request, DeleteAccount $delete): JsonResponse
    {
        $delete($this->user($request));

        return ApiResponse::item(['message' => __('account.api.deleted')]);
    }
}
