<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RideTemplate;
use App\Models\User;

final class RideTemplatePolicy
{
    public function view(User $user, RideTemplate $record): bool
    {
        return $record->user_id === $user->id;
    }
}
