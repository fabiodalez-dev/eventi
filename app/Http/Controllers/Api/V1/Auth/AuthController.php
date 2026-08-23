<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Account\RegisterUser;
use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Registrazione, accesso e uscita con token Sanctum (§13.4).
 *
 * Il login **social** non c'è: D7 lo ha rimandato perché Socialite non è
 * installabile su Laravel 13, e lo schema `users` non contiene nulla che
 * impedisca di aggiungerlo poi.
 *
 * Ogni accesso emette un token proprio, con il nome del dispositivo: chi
 * perde il telefono deve poter revocare quello e non il tablet. L'uscita
 * revoca **solo** il token con cui è stata chiesta, per la stessa ragione.
 *
 * La creazione dell'account non sta qui ma in `RegisterUser`: sito e API
 * registrano nello stesso modo, e due copie della stessa procedura sono due
 * occasioni di dimenticarsi l'email di verifica in una delle due.
 */
final class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterUser $register): JsonResponse
    {
        $data = $request->validated();

        $user = $register([
            'name' => is_string($data['name'] ?? null) ? $data['name'] : null,
            'email' => (string) $data['email'],
            'password' => (string) $data['password'],
            'marketing_opt_in' => $request->boolean('marketing_opt_in'),
        ]);

        return ApiResponse::item([
            'token' => $this->issueToken($user, $request->validated('device_name')),
            'user' => UserResource::toArray($user),
            'message' => __('api.auth.registered'),
        ], status: 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', (string) $request->validated('email'))->first();

        /*
         * La stessa risposta per "email inesistente" e "password sbagliata":
         * risposte diverse direbbero a chiunque quali indirizzi sono
         * registrati. `Hash::check` gira comunque, così il tempo di risposta
         * non racconta la stessa cosa.
         */
        $password = (string) $request->validated('password');

        if (! $user instanceof User || ! Hash::check($password, (string) $user->password)) {
            throw new ApiException(ApiErrorCode::InvalidCredentials);
        }

        $user->forceFill(['last_active_at' => Carbon::now()])->save();

        return ApiResponse::item([
            'token' => $this->issueToken($user, $request->validated('device_name')),
            'user' => UserResource::toArray($user),
            'message' => __('api.auth.logged_in'),
        ]);
    }

    /**
     * Esce da **questo** dispositivo. Revocare tutti i token a ogni uscita
     * butterebbe fuori anche il telefono di chi sta chiudendo la sessione sul
     * tablet, che non è ciò che chiede chi tocca "esci".
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ApiException(ApiErrorCode::Unauthenticated);
        }

        /*
         * Il token c'è di sicuro: la rotta passa da `auth:sanctum`, e in
         * questo progetto Sanctum autentica solo con token personali —
         * nessuna rotta dell'API è "stateful", quindi non esiste il caso del
         * token di sessione che non si può revocare.
         */
        $user->currentAccessToken()->delete();

        return ApiResponse::item(['message' => __('api.auth.logged_out')]);
    }

    private function issueToken(User $user, mixed $deviceName): string
    {
        $name = is_string($deviceName) && trim($deviceName) !== ''
            ? trim($deviceName)
            : __('api.auth.token_name');

        return $user->createToken($name)->plainTextToken;
    }
}
