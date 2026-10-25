<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Models\User;

/**
 * Gli id degli avvisi di una persona, letti una volta per richiesta: la scheda
 * di un evento chiede «avviso attivo?» per ogni data, e senza memoria sarebbe
 * una query per data. I servizi che lo usano sono legati come `scoped`
 * (`AppServiceProvider`), quindi la memoria muore con la richiesta.
 */
trait RemembersAlertIds
{
    /** @var array<int, list<int>> */
    private array $alertIds = [];

    /** @return list<int> */
    abstract public function ids(User $user): array;

    public function has(User $user, int $id): bool
    {
        return in_array($id, $this->alertIds[(int) $user->getKey()] ??= $this->ids($user), true);
    }

    private function forgetAlertIds(User $user): void
    {
        unset($this->alertIds[(int) $user->getKey()]);
    }
}
