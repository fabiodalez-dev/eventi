<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

/**
 * `POST /v1/submissions` (§13.1): la stessa proposta del modulo pubblico,
 * senza il campo esca.
 *
 * L'esca (`Honeypot`) ferma i robot che compilano moduli HTML; qui non ha
 * senso — chi parla con l'API compila JSON — e a fermare gli abusi resta il
 * limite di frequenza per indirizzo IP (§14.7).
 */
final class StoreSubmissionRequest extends ApiRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'starts_at_hint' => ['nullable', 'date'],
            'venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')->whereNull('deleted_at')],
            'venue_hint' => ['nullable', 'string', 'max:255'],
            'raw_text' => ['nullable', 'string', 'max:5000'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['required', 'email:filter', 'max:255'],
        ];
    }
}
