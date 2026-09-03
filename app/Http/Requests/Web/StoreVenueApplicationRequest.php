<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\VenueType;
use App\Support\Honeypot;
use App\Support\Turnstile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\Rules\Phone as PhoneRule;

/**
 * Richiesta di accreditamento di un locale (§11.1, §7.3).
 *
 * Il referente è un dato obbligatorio: l'accreditamento è un rapporto con una
 * persona, non con un indirizzo email generico, ed è la persona che la
 * redazione richiama per verificare.
 */
class StoreVenueApplicationRequest extends FormRequest
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
            'venue_name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(VenueType::values())],
            'address' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_role' => ['nullable', 'string', 'max:120'],
            /*
             * Il telefono si valida davvero, non solo per lunghezza.
             *
             * `max:40` accettava qualunque cosa: «chiamami», un indirizzo
             * email, quaranta lettere. E chi modera scopre che il numero non
             * serve a niente nel momento in cui prova a usarlo — cioè quando
             * ha bisogno di chiarire qualcosa su una richiesta.
             *
             * `IT` come regione implicita perché il modulo è italiano e
             * nessuno scrive il prefisso internazionale del proprio Paese;
             * `INTERNATIONAL` per non respingere un numero estero scritto per
             * intero, che su un evento di confine capita.
             */
            'contact_phone' => ['nullable', 'string', 'max:40', (new PhoneRule)->country('IT')->type(['mobile', 'fixed_line'])->international()],
            'contact_email' => ['required', 'email:filter', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            ...Honeypot::rules(),
            ...Turnstile::rules('registrazione-locale'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> $fields */
        $fields = trans('forms.application.fields');

        return $fields;
    }
}
