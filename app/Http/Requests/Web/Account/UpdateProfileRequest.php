<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use App\DTOs\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Il profilo e le preferenze di notifica in un modulo solo (§15.2, §15.4).
 *
 * Sono due tabelle di §15.8 — `/v1/me` e `/v1/me/notification-preferences` —
 * ma una sola pagina: chiedere a una persona di salvare due volte per cambiare
 * il proprio nome e l'orario del riepilogo non ha alcuna giustificazione.
 *
 * Le caselle non spuntate non arrivano affatto in una richiesta HTML: per
 * questo le preferenze si leggono con `boolean()` sul valore assente e non con
 * `sometimes`, che le lascerebbe al valore precedente rendendole impossibili
 * da spegnere.
 */
class UpdateProfileRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:120'],
            'timezone' => ['required', 'string', 'timezone'],
            'locale' => ['required', 'string', Rule::in(config()->array('account.locales'))],
            'marketing_opt_in' => ['nullable', 'boolean'],
            'reminders' => ['nullable', 'boolean'],
            'sold_out' => ['nullable', 'boolean'],
            'venue_digest' => ['nullable', 'boolean'],
            'comments' => ['sometimes', 'boolean'],
            'daily_digest' => ['nullable', 'boolean'],
            'daily_digest_time' => ['nullable', 'date_format:H:i'],
            'tonight' => ['nullable', 'boolean'],
            'venue_report' => ['nullable', 'boolean'],
            'tonight_time' => ['nullable', 'date_format:H:i'],
            /* Come nelle preferenze: un elenco di giorni vuoto, o assente perché nessuna casella è spuntata, non deve passare per una conferma. */
            'tonight_days' => ['required_if_accepted:tonight', 'nullable', 'array', 'min:1', 'max:7'],
            'tonight_days.*' => ['integer', 'between:1,7'],
            'quiet_from' => ['nullable', 'date_format:H:i', 'required_with:quiet_to'],
            'quiet_to' => ['nullable', 'date_format:H:i', 'required_with:quiet_from'],
            'quiet_off' => ['nullable', 'boolean'],
        ];
    }

    public function preferences(): NotificationPreferences
    {
        return NotificationPreferences::fromUser($this->user())->with([
            'reminders' => $this->boolean('reminders'),
            'sold_out' => $this->boolean('sold_out'),
            'venue_digest' => $this->boolean('venue_digest'),
            'daily_digest' => $this->boolean('daily_digest'),
            'tonight' => $this->boolean('tonight'),
            'venue_report' => $this->boolean('venue_report'),
            'tonight_days' => $this->validated('tonight_days', $this->user()->notificationPreferences()->tonightDays),
            'tonight_time' => $this->validated('tonight_time') ?: $this->user()->notificationPreferences()->tonightTime,
            'comments' => $this->boolean('comments'),
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

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('account.profile.name'),
            'timezone' => __('account.profile.timezone'),
            'locale' => __('account.profile.locale'),
        ];
    }
}
