<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use App\DTOs\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Le preferenze di notifica cambiate **senza accesso**, dal collegamento
 * firmato in fondo a ogni email (§15.9).
 *
 * È lo stesso insieme di caselle del profilo, senza i campi che riguardano
 * l'identità: chi arriva qui ha in mano la prova che quell'indirizzo è suo —
 * la firma — non le credenziali per cambiare nome, lingua o fuso.
 *
 * Le caselle non spuntate non arrivano affatto in una richiesta HTML: si
 * leggono con `boolean()` sul valore assente, mai con `sometimes`, che le
 * lascerebbe accese rendendole impossibili da spegnere.
 */
class UpdateNotificationSettingsRequest extends FormRequest
{
    /**
     * La firma dell'indirizzo è l'autorizzazione, e la verifica il middleware
     * `signed` prima che questa richiesta esista.
     */
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
            'reminders' => ['nullable', 'boolean'],
            'sold_out' => ['nullable', 'boolean'],
            'venue_digest' => ['nullable', 'boolean'],
            'daily_digest' => ['nullable', 'boolean'],
            'daily_digest_time' => ['nullable', 'date_format:H:i'],
            'quiet_from' => ['nullable', 'date_format:H:i', 'required_with:quiet_to'],
            'quiet_to' => ['nullable', 'date_format:H:i', 'required_with:quiet_from'],
            'marketing_opt_in' => ['nullable', 'boolean'],
        ];
    }

    public function preferences(): NotificationPreferences
    {
        return NotificationPreferences::defaults()->with([
            'reminders' => $this->boolean('reminders'),
            'sold_out' => $this->boolean('sold_out'),
            'venue_digest' => $this->boolean('venue_digest'),
            'daily_digest' => $this->boolean('daily_digest'),
        ]);
    }

    /**
     * @return array<string, string>|null
     */
    public function quietHours(): ?array
    {
        $from = $this->string('quiet_from')->value();
        $to = $this->string('quiet_to')->value();

        return $from === '' || $to === '' ? null : ['from' => $from, 'to' => $to];
    }
}
