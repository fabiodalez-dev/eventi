<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * `POST /v1/auth/login` (§13.4).
 *
 * `device_name` dà un nome al token, perché un utente deve poter revocare il
 * telefono che ha perso senza perdere anche il tablet.
 */
final class LoginRequest extends ApiRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:filter', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
