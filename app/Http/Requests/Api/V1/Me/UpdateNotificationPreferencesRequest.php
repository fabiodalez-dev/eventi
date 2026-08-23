<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Me;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /v1/me/notification-preferences` (§15.4).
 *
 * Gli **annullamenti non sono qui** e non ci saranno: §15.4 li dichiara
 * «attivi, non disattivabili». Chiedere di spegnerli non produce un errore né
 * un effetto: la chiave semplicemente non esiste, e questo è il modo più
 * chiaro di dirlo a un client.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
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
            'reminders' => ['sometimes', 'boolean'],
            'reminder_hours' => ['sometimes', 'array', 'max:4'],
            'reminder_hours.*' => ['integer', 'between:1,168'],
            'sold_out' => ['sometimes', 'boolean'],
            'venue_digest' => ['sometimes', 'boolean'],
            'daily_digest' => ['sometimes', 'boolean'],
        ];
    }
}
