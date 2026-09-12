<?php

declare(strict_types=1);

namespace App\Http\Requests\Web\Account;

use App\Rules\PublicPushEndpoint;
use Illuminate\Foundation\Http\FormRequest;

/**
 * L'iscrizione che il browser consegna dopo il permesso (§15.6, D54).
 *
 * I tre campi sono quelli dell'oggetto `PushSubscription` del browser, con i
 * nomi che il browser stesso usa: `endpoint` è l'indirizzo del servizio push,
 * `p256dh` la chiave pubblica del dispositivo e `auth` il segreto con cui il
 * payload viene cifrato. Rinominarli qui costringerebbe il codice del browser
 * a tradurli, cioè a poterli sbagliare.
 *
 * Le due chiavi sono **obbligatorie insieme all'endpoint**: un'iscrizione
 * senza di esse è una riga che il canale prenderebbe e non potrebbe cifrare,
 * e l'errore comparirebbe settimane dopo dentro un job in coda.
 */
final class StorePushSubscriptionRequest extends FormRequest
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
            'endpoint' => ['required', 'string', 'url:https', new PublicPushEndpoint, 'max:512'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ];
    }

    public function endpoint(): string
    {
        return (string) $this->validated('endpoint');
    }

    /**
     * @return array{p256dh: string, auth: string}
     */
    public function keys(): array
    {
        return [
            'p256dh' => (string) $this->validated('keys.p256dh'),
            'auth' => (string) $this->validated('keys.auth'),
        ];
    }
}
