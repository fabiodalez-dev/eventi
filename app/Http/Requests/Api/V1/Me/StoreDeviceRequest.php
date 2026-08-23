<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Me;

use App\Enums\DevicePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /v1/me/devices` (§15.8).
 *
 * Il dispositivo si registra anche adesso che non esiste alcun canale push
 * (D8): serve a §15.6 per sapere quale dispositivo è stato attivo negli ultimi
 * trenta giorni, che è ciò che decide il canale. La stessa chiamata varrà per
 * FCM in fase F11 senza cambiare forma.
 *
 * Un dispositivo si riconosce dal proprio riferimento — il token FCM o
 * l'`endpoint` Web Push — e la seconda registrazione dello stesso aggiorna la
 * riga invece di crearne un'altra: §3.14 di `SCHEMA.md` lo dice esplicitamente,
 * la deduplica avviene qui e non con un indice unico.
 */
class StoreDeviceRequest extends FormRequest
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
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'push_token' => ['nullable', 'string', 'max:512', 'required_without:endpoint'],
            'endpoint' => ['nullable', 'string', 'max:512', 'required_without:push_token'],
            'keys' => ['nullable', 'array'],
            'keys.*' => ['string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:5'],
        ];
    }

    public function platform(): DevicePlatform
    {
        return DevicePlatform::from((string) $this->validated('platform'));
    }
}
