<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * `POST /v1/auth/register` (§13.4, §15.2).
 *
 * Si chiede il minimo: email e password, con il nome facoltativo (§16). Il consenso marketing è
 * separato e facoltativo, perché è giuridicamente un'altra cosa dalle
 * notifiche transazionali (§15.9) — e nasce spento.
 */
final class RegisterRequest extends ApiRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /* Il nome è facoltativo (§15.2): si raccoglie il minimo, e il
               minimo è email e password. */
            'name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'password_confirmation' => ['sometimes', 'required', 'same:password'],
            'email' => ['required', 'email:filter', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'device_name' => ['nullable', 'string', 'max:120'],
            'marketing_opt_in' => ['nullable', 'boolean'],
        ];
    }
}
