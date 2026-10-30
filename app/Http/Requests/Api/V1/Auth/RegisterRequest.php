<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiRequest;
use Illuminate\Validation\Rules\Password;

/**
 * `POST /v1/auth/register` (§13.4, §15.2).
 *
 * Si chiede il minimo: email e password, con il nome facoltativo (§16). La
 * risposta è la stessa che l'indirizzo sia nuovo o già registrato (D64): gli
 * errori di validazione riguardano solo i campi, mai l'esistenza dell'email. Il consenso marketing è
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
            /* Niente `unique`: «indirizzo già usato» direbbe a chiunque chi è
               iscritto. Un indirizzo già registrato riceve un messaggio, non
               un errore (`RequestRegistration`). */
            'email' => ['required', 'email:filter', 'max:255'],
            'password' => ['required', 'string', Password::defaults()],
            'device_name' => ['nullable', 'string', 'max:120'],
            'marketing_opt_in' => ['nullable', 'boolean'],
            /* La sfida PKCE dell'installazione: il collegamento che arriva per
               posta si scambia solo con il suo verificatore, come quello di
               `POST /v1/auth/magic-link`. Facoltativa per le versioni dell'app
               che non la mandano. */
            'code_challenge' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D'],
        ];
    }
}
