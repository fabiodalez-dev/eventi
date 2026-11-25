<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Models\User;
use App\Support\Honeypot;
use App\Support\Turnstile;
use App\Support\VenueApplications\ApplicationRules;
use Illuminate\Foundation\Http\FormRequest;

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
            ...ApplicationRules::organizer(),
            ...ApplicationRules::contact(),
            /* Da collegati la richiesta parte a nome dell'account, con la sua
               email: quella del modulo non conta. */
            'contact_email' => $this->user() instanceof User ? ['nullable'] : ['required', 'email:filter', 'max:255'],
            StoreVenueApplicationRequest::STARTED_FIELD => StoreVenueApplicationRequest::startedRules(),
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

    /** L'email a cui la richiesta risponde: quella dell'account, se c'è. */
    public function applicantEmail(): string
    {
        $user = $this->user();

        return mb_strtolower(trim($user instanceof User ? $user->email : (string) $this->validated('contact_email')));
    }
}
