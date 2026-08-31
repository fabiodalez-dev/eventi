<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Page;
use App\Models\User;

/**
 * Chi scrive le pagine legali. Non il moderatore: §3 gli assegna la
 * moderazione dei contenuti altrui, non i testi con cui il sito risponde
 * davanti a un'autorità. Il permesso sta con «configurazione», cioè
 * amministratore e amministratore di sistema.
 */
class PagePolicy
{
    public function viewAny(?User $user): bool
    {
        return $user?->can(Permission::ManagePages->value) ?? false;
    }

    public function view(?User $user, Page $page): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManagePages->value);
    }

    public function update(User $user, Page $page): bool
    {
        return $user->can(Permission::ManagePages->value);
    }

    /**
     * Le pagine si spengono con `is_published`, non si cancellano: un
     * indirizzo che ha ricevuto collegamenti per mesi non deve diventare un
     * 404 perché qualcuno ha riordinato l'elenco. Resta possibile a chi ha il
     * permesso, ma è un gesto dichiarato.
     */
    public function delete(User $user, Page $page): bool
    {
        return $user->can(Permission::ManagePages->value);
    }
}
