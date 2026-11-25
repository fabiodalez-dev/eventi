<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Dates;

use App\Models\User;

/** «Elimina» una data (solo il locale; `EventOccurrencePolicy::delete`). */
final class DateDeleteRequest extends ManagedDateRequest
{
    protected function allowed(User $user): bool
    {
        return $user->can('delete', $this->occurrence());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
