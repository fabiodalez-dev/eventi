<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/auth/magic-link` (§15.2).
 *
 * Come per la reimpostazione della password, la risposta è sempre la stessa
 * che l'indirizzo esista o no: dire «questa email non è registrata» regala a
 * chiunque l'elenco degli account.
 */
class MagicLinkRequest extends FormRequest
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
            'code_challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D'],
            'email' => ['required', 'email', 'max:255'],
        ];
    }
}
