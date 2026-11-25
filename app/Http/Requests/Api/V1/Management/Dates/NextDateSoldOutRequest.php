<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management\Dates;

use App\Models\User;

/**
 * «Tutto esaurito» / «Di nuovo disponibile» sulla prossima data, dalla riga
 * dell'elenco eventi (solo il locale). Il permesso vero è quello della data
 * (`EventOccurrencePolicy::update`), che il controller chiede dopo averla
 * trovata; qui basta chi può modificare l'evento.
 */
final class NextDateSoldOutRequest extends ManagedDateRequest
{
    protected function allowed(User $user): bool
    {
        return $user->can('update', $this->event());
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['sold_out' => ['required', 'boolean']];
    }
}
