<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Applications;

use App\Support\VenueApplications\ApplicationRules;

/** «Registra la tua organizzazione» dall'app (W-AP-05). */
final class OrganizerApplicationRequest extends ApplicationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [...ApplicationRules::organizer(), ...ApplicationRules::contact()];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...parent::attributes(), ...(array) trans('forms.organizer_application.fields')];
    }
}
