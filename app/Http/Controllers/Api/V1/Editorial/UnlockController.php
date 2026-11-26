<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Editorial\EditorialUnlocks;
use App\Support\Editorial\EditorialDesk;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Lo sblocco della redazione con la password dell'account (decisioni 2-4).
 *
 * Una password giusta sblocca la redazione su questo token per 12 ore e
 * conferma le azioni pericolose per 15 minuti; ogni tentativo, giusto o
 * sbagliato, va nel registro di sicurezza con `canale: app`. Il limite di
 * frequenza è stretto (`editorial-unlock`, 5 ogni 15 minuti per persona):
 * è un indirizzo che prova password.
 */
final class UnlockController extends Controller
{
    /**
     * Sblocca la redazione con la password dell'account.
     *
     * Corpo: `{"password": "…"}`. Risponde con lo stesso oggetto `unlock` di
     * `GET desk`. Password sbagliata: 422 su `error.fields.password`. Senza un
     * token dell'app (un accesso dal sito): 423 `EDITORIAL_LOCKED`, perché lo
     * sblocco si lega al token.
     */
    public function __invoke(Request $request, EditorialUnlocks $unlocks): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $desk = EditorialDesk::require();

        $password = $request->input('password');
        if (! is_string($password) || $password === '' || mb_strlen($password) > 255) {
            throw new ApiException(ApiErrorCode::ValidationFailed, fields: ['password' => [__('validation.required', ['attribute' => 'password'])]]);
        }

        $tokenId = $unlocks->tokenId($request);
        if ($tokenId === null) {
            throw new ApiException(ApiErrorCode::EditorialLocked);
        }

        // Un account senza password (entrato solo con il collegamento) non sblocca: la imposta prima dal sito.
        $stored = $user->getAuthPassword();
        if ($stored === '' || ! Hash::check($password, $stored)) {
            SecurityLog::scrivi('redazione_sblocco_fallito', $user, ['canale' => 'app', 'token_id' => $tokenId, 'ruolo' => $desk->role->value], causa: $user);

            throw new ApiException(ApiErrorCode::ValidationFailed, fields: ['password' => [__('validation.current_password')]]);
        }

        $unlock = $unlocks->unlock($user, $tokenId);
        SecurityLog::scrivi('redazione_sbloccata', $user, ['canale' => 'app', 'token_id' => $tokenId, 'ruolo' => $desk->role->value], causa: $user);

        return response()->json(['data' => $unlocks->state($unlock)]);
    }
}
