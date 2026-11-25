<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Dates;

use App\Models\User;

/** Una data dell'evento e la sua locandina in PDF (`EventPolicy::view`, cioè chi è del soggetto). */
final class DateViewRequest extends ManagedDateRequest
{
    protected function allowed(User $user): bool
    {
        return $user->can('view', $this->event());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
