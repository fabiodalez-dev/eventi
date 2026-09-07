<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use App\DTOs\NotificationPreferences;
use App\Enums\NotificationDelivery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'delivery' => ['sometimes', Rule::enum(NotificationDelivery::class)],
            'reminders' => ['nullable', 'boolean'],
            'sold_out' => ['nullable', 'boolean'],
            'venue_digest' => ['nullable', 'boolean'],
            'daily_digest' => ['nullable', 'boolean'],
            'daily_digest_time' => ['nullable', 'date_format:H:i'],
            'quiet_from' => ['nullable', 'date_format:H:i', 'required_with:quiet_to'],
            'quiet_to' => ['nullable', 'date_format:H:i', 'required_with:quiet_from'],
            'quiet_off' => ['nullable', 'boolean'],
            'marketing_opt_in' => ['nullable', 'boolean'],
        ];
    }

    public function preferences(): NotificationPreferences
    {
        return NotificationPreferences::fromUser($this->route('user'))->with([
            'delivery' => $this->validated('delivery', $this->route('user')->notificationPreferences()->delivery->value),
            'reminders' => $this->boolean('reminders'),
            'sold_out' => $this->boolean('sold_out'),
            'venue_digest' => $this->boolean('venue_digest'),
            'daily_digest' => $this->boolean('daily_digest'),
        ]);
    }

    /**
     * Il valore da scrivere in `users.quiet_hours`, nei tre stati che quella
     * colonna distingue (D36):
     *
     * - un array con gli orari → sono le ore di silenzio scelte;
     * - un array **vuoto** → «nessun silenzio», scelto di proposito. Senza
     *   questo terzo stato la finestra predefinita sarebbe impossibile da
     *   spegnere: svuotare i campi tornerebbe a `null`, cioè al predefinito;
     * - `null` → non ho scelto, vale il predefinito.
     *
     * @return array<string, string>|null
     */
    public function quietHours(): ?array
    {
        if ($this->boolean('quiet_off')) {
            return [];
        }

        $from = $this->string('quiet_from')->value();
        $to = $this->string('quiet_to')->value();

        return $from === '' || $to === '' ? null : ['from' => $from, 'to' => $to];
    }
}
