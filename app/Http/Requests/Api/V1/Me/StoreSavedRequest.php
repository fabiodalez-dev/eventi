<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Me;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/me/saved` (§15.8). **Si salva un'occorrenza** (§15.3): il campo si
 * chiama `occurrence_id` e non `event_id` perché è la data che si mette in
 * agenda, e un evento con dieci serate non dice quale.
 */
class StoreSavedRequest extends FormRequest
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
            'occurrence_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
