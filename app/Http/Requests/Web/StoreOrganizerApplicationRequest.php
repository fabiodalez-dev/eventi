<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Support\Honeypot;
use App\Support\Turnstile;
use Illuminate\Foundation\Http\FormRequest;
use Propaganistas\LaravelPhone\Rules\Phone as PhoneRule;

/**
 * La richiesta di un organizzatore: come quella di un locale, senza tipo e
 * senza indirizzo, perché un collettivo non ha né l'uno né l'altro.
 */
class StoreOrganizerApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'venue_name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_role' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40', (new PhoneRule)->country('IT')->type(['mobile', 'fixed_line'])->international()],
            'contact_email' => ['required', 'email:filter', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            ...Honeypot::rules(),
            ...Turnstile::rules('registrazione-organizzatore'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        /** @var array<string, string> $fields */
        $fields = trans('forms.application.fields');

        return [...$fields, ...(array) trans('forms.organizer_application.fields')];
    }
}
