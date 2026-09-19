<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CarpoolAudit;
use App\Models\User;
use App\Services\Carpool\CommunitySafety;

final class CarpoolAuditPolicy
{
    public function view(User $user, CarpoolAudit $record): bool
    {
        return app(CommunitySafety::class)->staff($user, Permission::ReadCommunitySecurity, true);
    }
}
