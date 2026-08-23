<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiRequest;
use Illuminate\Validation\Rules\Password;

/**
 * `POST /v1/auth/password/reset` (§13.4).
 *
 * Non si chiede la conferma della password: chi manda JSON non digita due
 * volte lo stesso campo, e un secondo campo obbligatorio sarebbe soltanto un
 * modo in più di sbagliare la chiamata.
 */
final class ResetPasswordRequest extends ApiRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email:filter', 'max:255'],
            'password' => ['required', 'string', Password::defaults()],
        ];
    }
}
