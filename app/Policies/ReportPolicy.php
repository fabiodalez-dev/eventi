<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Report;
use App\Models\User;

class ReportPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(User $user, Report $report): bool
    {
        return $report->reporter_user_id === $user->id || $user->can(Permission::ViewReports->value);
    }

    /**
     * Chiunque, anche un ospite, può segnalare un errore (§3 del piano —
     * "Guest: segnalare errori").
     */
    public function create(?User $user): bool
    {
        return true;
    }

    /**
     * Cambiare stato o aggiungere una nota di risoluzione è sempre
     * un'azione di moderazione.
     */
    public function update(User $user, Report $report): bool
    {
        return $user->can(Permission::ResolveReports->value);
    }

    public function delete(User $user, Report $report): bool
    {
        return $user->can(Permission::ResolveReports->value);
    }
}
