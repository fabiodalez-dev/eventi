<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Applications;

use App\Support\VenueApplications\ApplicationRules;

/**
 * «Questo locale è mio» dall'app (W-AP-04): il referente e come verificarlo.
 * Il locale lo sceglie la rotta; se non si può più rivendicare il controller
 * risponde 404 prima di guardare i campi.
 */
final class ClaimRequest extends ApplicationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [...ApplicationRules::claim(), ...ApplicationRules::contact()];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...parent::attributes(), 'message' => (string) __('forms.claim.fields.message')];
    }
}
