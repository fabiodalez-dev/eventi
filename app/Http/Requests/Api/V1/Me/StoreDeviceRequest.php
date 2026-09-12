<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Me;

use App\Enums\DevicePlatform;
use App\Rules\PublicPushEndpoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /v1/me/devices` (§15.8).
 *
 * Serve a §15.6 per sapere quale dispositivo è stato attivo negli ultimi
 * trenta giorni, che è ciò che decide il canale. Da D54 un dispositivo `web`
 * con `endpoint` e `keys` riceve davvero le notifiche; la stessa chiamata
 * varrà per FCM in fase F11 senza cambiare forma.
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
            'installation_id' => ['nullable', 'required_if:platform,android,ios', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'push_token' => ['nullable', 'required_if:platform,android,ios', 'string', 'max:512', 'required_without:endpoint'],
            /*
             * `url:https` come nel gemello del sito
             * (`Web\Account\StorePushSubscriptionRequest`), che la regola
             * severa ce l'aveva già.
             *
             * Qui era una stringa qualunque da 512 caratteri: nessuno schema
             * imposto, nessun controllo dell'host. Il servizio push poi ci
             * manda una richiesta HTTP — quindi era un indirizzo scelto da chi
             * chiama verso cui il server (o il servizio a suo nome) parla:
             * SSRF con un passaggio in più, e `http://` o indirizzi interni
             * passavano la validazione.
             */
            'endpoint' => ['nullable', 'required_if:platform,web', 'string', 'url:https', new PublicPushEndpoint, 'max:512', 'required_without:push_token'],

            /*
             * `keys.*` limitava la lunghezza di OGNI elemento e nulla limitava
             * QUANTI ce ne fossero: `DeviceController::store()` scriveva
             * l'array intero in una colonna JSON, quindi bastava un array da
             * centomila voci da 255 caratteri per depositare megabyte per
             * riga — con un account qualsiasi, e su uno spazio da 10 GB.
             *
             * Le chiavi ammesse sono due e si chiamano: il protocollo Web Push
             * prevede `p256dh` e `auth`, come dichiara già il gemello del
             * sito. Dichiararle per nome chiude insieme il numero e la forma.
             */
            'keys' => ['nullable', 'array:p256dh,auth'],
            'keys.p256dh' => ['nullable', 'string', 'max:255'],
            'keys.auth' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:5'],
        ];
    }

    public function platform(): DevicePlatform
    {
        return DevicePlatform::from((string) $this->validated('platform'));
    }
}
