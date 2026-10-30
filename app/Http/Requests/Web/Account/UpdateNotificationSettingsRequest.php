<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use App\DTOs\NotificationPreferences;
use App\DTOs\QuietHours;
use App\Enums\NotificationDelivery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
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
            'comments' => ['sometimes', 'boolean'],
            'daily_digest' => ['nullable', 'boolean'],
            'daily_digest_time' => ['nullable', 'date_format:H:i'],
            'tonight' => ['nullable', 'boolean'],
            'venue_report' => ['nullable', 'boolean'],
            'tonight_time' => ['nullable', 'date_format:H:i'],
            /* Le caselle dei giorni non arrivano affatto quando si toglie l'ultima spunta: senza `required_if_accepted` la richiesta sarebbe valida, il salvataggio confermato e i giorni di prima resterebbero al loro posto. */
            'tonight_days' => ['required_if_accepted:tonight', 'nullable', 'array', 'min:1', 'max:7'],
            'tonight_days.*' => ['integer', 'between:1,7'],
            'quiet_from' => ['nullable', 'date_format:H:i', 'required_with:quiet_to'],
            'quiet_to' => ['nullable', 'date_format:H:i', 'required_with:quiet_from'],
            'quiet_off' => ['nullable', 'boolean'],
            'marketing_opt_in' => ['nullable', 'boolean'],
        ];
    }

    /**
     * C'è una sessione aperta su questo stesso account? Senza, la sola prova è
     * la firma, e il collegamento firmato arriva per email: può essere
     * inoltrato o finire in un archivio pubblico. Con la sola firma si può
     * quindi soltanto **ridurre** ciò che si riceve (D54): spegnere, mai
     * accendere, e il consenso al marketing si può solo revocare.
     */
    public function ownsAccount(): bool
    {
        return Auth::id() === $this->route('user')->getKey();
    }

    public function preferences(): NotificationPreferences
    {
        $requested = $this->requestedPreferences();

        if ($this->ownsAccount()) {
            return $requested;
        }

        /* Ogni interruttore resta acceso solo se era acceso e lo si lascia
           acceso; consegna, giorni e orario restano quelli salvati. */
        $current = NotificationPreferences::fromUser($this->route('user'));

        return $current->with([
            'reminders' => $current->reminders && $requested->reminders,
            'sold_out' => $current->soldOut && $requested->soldOut,
            'venue_digest' => $current->venueDigest && $requested->venueDigest,
            'daily_digest' => $current->dailyDigest && $requested->dailyDigest,
            'tonight' => $current->tonight && $requested->tonight,
            'venue_report' => $current->venueReport && $requested->venueReport,
            'comments' => $current->comments && $requested->comments,
        ]);
    }

    private function requestedPreferences(): NotificationPreferences
    {
        return NotificationPreferences::fromUser($this->route('user'))->with([
            'delivery' => $this->validated('delivery', $this->route('user')->notificationPreferences()->delivery->value),
            'reminders' => $this->boolean('reminders'),
            'sold_out' => $this->boolean('sold_out'),
            'venue_digest' => $this->boolean('venue_digest'),
            'daily_digest' => $this->boolean('daily_digest'),
            'tonight' => $this->boolean('tonight'),
            'venue_report' => $this->boolean('venue_report'),
            'tonight_days' => $this->validated('tonight_days', $this->route('user')->notificationPreferences()->tonightDays),
            'tonight_time' => $this->validated('tonight_time') ?: $this->route('user')->notificationPreferences()->tonightTime,
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
        $from = $this->string('quiet_from')->value();
        $to = $this->string('quiet_to')->value();
        $window = $from === '' || $to === '' ? null : ['from' => $from, 'to' => $to];

        if (! $this->ownsAccount()) {
            /* Con la sola firma il silenzio si può allargare, mai togliere né
               accorciare: `quiet_off` non conta, e una finestra nuova vale solo
               se contiene per intero quella che vale oggi. */
            $user = $this->route('user');
            $current = QuietHours::fromUser($user);
            $requested = $window === null || $this->boolean('quiet_off') ? null : QuietHours::fromWindow($from, $to);

            return $requested !== null && ($current === null || $requested->covers($current)) ? $window : $user->quiet_hours;
        }

        if ($this->boolean('quiet_off')) {
            return [];
        }

        return $window;
    }
}
