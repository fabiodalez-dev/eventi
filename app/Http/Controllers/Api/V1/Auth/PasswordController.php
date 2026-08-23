<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Reimpostazione della password (§13.4).
 *
 * `forgot` risponde **sempre** allo stesso modo, che l'indirizzo esista o no:
 * una risposta diversa direbbe a chiunque quali email sono registrate, ed è
 * il modo più economico per costruire una lista di bersagli.
 *
 * Il messaggio non è quello di Laravel ma `ResetPasswordLink`, perché i testi
 * stanno in `lang/it` e il collegamento porta all'applicazione.
 */
final class PasswordController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker()->sendResetLink(
            ['email' => (string) $request->validated('email')],
            static fn (User $user, string $token) => $user->notify(new ResetPasswordLink($token)),
        );

        return ApiResponse::item(['message' => __('api.auth.password_link_sent')]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            [
                'email' => (string) $request->validated('email'),
                'password' => (string) $request->validated('password'),
                'token' => (string) $request->validated('token'),
            ],
            static function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                /*
                 * Cambiare la password butta fuori i dispositivi: se qualcuno
                 * ha reimpostato la password perché temeva un accesso altrui,
                 * lasciare vivi i token vecchi renderebbe il gesto inutile.
                 */
                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException(
                ApiErrorCode::ValidationFailed,
                __('api.auth.password_reset_failed'),
                ['token' => [__('api.auth.password_reset_failed')]],
            );
        }

        return ApiResponse::item(['message' => __('api.auth.password_reset')]);
    }
}
