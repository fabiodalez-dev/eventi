<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RideSearch;
use App\Models\User;

final class RideSearchPolicy
{
    public function view(User $user, RideSearch $record): bool
    {
        return $record->user_id === $user->id;
    }
}
