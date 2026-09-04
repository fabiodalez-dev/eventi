<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Redirect;
use App\Models\User;

/**
 * Chi decide dove va a finire un vecchio indirizzo.
 *
 * Il permesso è quello delle pagine redazionali e non uno nuovo: è la stessa
 * domanda — chi possiede gli indirizzi del sito — e un permesso in più andrebbe
 * assegnato ai ruoli in un seeder, cioè si aggiungerebbe un pezzo di
 * configurazione da tenere allineato per non dire niente di diverso. Non il
 * moderatore: §3 gli assegna i contenuti altrui, non la struttura del sito.
 */
class RedirectPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user?->can(Permission::ManagePages->value) ?? false;
    }

    public function view(?User $user, Redirect $redirect): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManagePages->value);
    }

    public function update(User $user, Redirect $redirect): bool
    {
        return $user->can(Permission::ManagePages->value);
    }

    /**
     * Si cancella, a differenza di una pagina: una riga qui non è un contenuto,
     * è un ponte. Quando i passaggi sono fermi da un anno il ponte non porta
     * più nessuno, e un elenco che si può solo allungare è un elenco che
     * nessuno rilegge.
     */
    public function delete(User $user, Redirect $redirect): bool
    {
        return $user->can(Permission::ManagePages->value);
    }
}
