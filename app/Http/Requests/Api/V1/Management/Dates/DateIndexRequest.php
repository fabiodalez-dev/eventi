<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Dates;

use App\Models\User;
use Illuminate\Validation\Rule;

/** L'elenco delle date di un evento (`EventPolicy::view`, cioè chi è del soggetto). */
final class DateIndexRequest extends ManagedDateRequest
{
    protected function allowed(User $user): bool
    {
        return $user->can('view', $this->event());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(['all', 'upcoming', 'past'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
