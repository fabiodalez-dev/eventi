<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CarpoolProfile;
use App\Models\User;

final class CarpoolProfilePolicy
{
    public function view(User $user, CarpoolProfile $record): bool
    {
        return $record->user_id === $user->id;
    }
}
