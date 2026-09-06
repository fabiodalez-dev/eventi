<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SocialBatch;
use App\Models\User;

class SocialBatchPolicy
{
    public function view(User $user, SocialBatch $batch): bool
    {
        return $user->hasAnyRole(['admin', 'super_admin']) || ($batch->venue_id !== null && $user->venues()->where('venues.id', $batch->venue_id)->exists());
    }
}
