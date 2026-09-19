<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RideFeedback;
use App\Models\User;

final class RideFeedbackPolicy
{
    public function view(User $user, RideFeedback $record): bool
    {
        return $record->user_id === $user->id;
    }
}
