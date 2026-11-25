<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Dates;

use App\Enums\RecurrenceFrequency;
use App\Enums\Weekday;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * «Ripeti» sull'evento del locale (`EventPolicy::update`): frequenza, giorni
 * della settimana, «fino al» compreso, nella data della città.
 */
final class RecurrenceRequest extends ManagedDateRequest
{
    protected function allowed(User $user): bool
    {
        return $user->can('update', $this->event());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'frequency' => ['required', Rule::in(RecurrenceFrequency::values())],
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['string', 'distinct', Rule::in(array_column(Weekday::cases(), 'value'))],
            'until' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
