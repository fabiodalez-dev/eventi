<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/auth/verify-email` (§15.8).
 *
 * Il campo è l'**indirizzo completo** ricevuto per posta, firma compresa, e
 * non la coppia `id`+`hash`: quel `hash` è `sha1(email)`, cioè un valore che
 * chiunque conosca l'indirizzo può calcolare. È la firma a rendere il
 * collegamento una prova, e la firma copre l'indirizzo intero — quindi
 * l'indirizzo intero è ciò che va rimandato indietro.
 */
class VerifyEmailRequest extends FormRequest
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
            'url' => ['required', 'string', 'url', 'max:2048'],
        ];
    }
}
