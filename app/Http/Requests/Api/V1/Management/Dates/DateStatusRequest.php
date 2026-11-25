<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Dates;

use App\Enums\OccurrenceScope;
use App\Enums\OccurrenceStatus;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * I due gesti del locale sullo stato di una data: «Annulla» (anche «questa e
 * le successive») e «Tutto esaurito» / «Di nuovo disponibile»
 * (`EventOccurrencePolicy::update`).
 */
final class DateStatusRequest extends ManagedDateRequest
{
    protected function allowed(User $user): bool
    {
        return $user->can('update', $this->occurrence());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([OccurrenceStatus::Cancelled->value, OccurrenceStatus::SoldOut->value, OccurrenceStatus::Scheduled->value])],
            'scope' => ['nullable', Rule::in(OccurrenceScope::values())],
        ];
    }

    public function status(): OccurrenceStatus
    {
        return OccurrenceStatus::from($this->string('status')->toString());
    }

    public function scope(): OccurrenceScope
    {
        return OccurrenceScope::tryFrom($this->string('scope')->toString()) ?? OccurrenceScope::Single;
    }
}
