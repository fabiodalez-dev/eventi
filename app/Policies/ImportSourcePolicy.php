<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ImportSource;
use App\Models\User;

/**
 * Le sorgenti di import sono uno strumento redazionale (§14.2 del piano):
 * il `venue_id` opzionale identifica il locale coperto dal feed, ma la
 * gestione della sorgente resta sempre allo staff, mai al locale stesso.
 */
class ImportSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ManageImportSources->value);
    }

    public function view(User $user, ImportSource $importSource): bool
    {
        return $user->can(Permission::ManageImportSources->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageImportSources->value);
    }

    public function update(User $user, ImportSource $importSource): bool
    {
        return $user->can(Permission::ManageImportSources->value);
    }

    public function delete(User $user, ImportSource $importSource): bool
    {
        return $user->can(Permission::ManageImportSources->value);
    }
}
