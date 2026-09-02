<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use App\Support\Honeypot;
use App\Support\Turnstile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Registrazione dal sito (§15.2). Il nome è facoltativo, il consenso alla
 * newsletter è separato e nasce spento (§15.9), e il campo esca ferma i robot
 * generici come su ogni altro modulo pubblico (§14.7).
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email:filter', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'marketing_opt_in' => ['nullable', 'boolean'],
            ...Honeypot::rules(),
            ...Turnstile::rules('registrazione-utente'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('account.register.name'),
            'email' => __('account.register.email'),
            'password' => __('account.register.password'),
        ];
    }
}
