<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            /*
             * `confirmed` pretende `password_confirmation`: una password
             * scritta male qui non si scopre al primo accesso sbagliato, si
             * scopre subito.
             */
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
