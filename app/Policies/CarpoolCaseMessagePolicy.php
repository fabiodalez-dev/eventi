<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CarpoolCaseMessage;
use App\Models\User;

final class CarpoolCaseMessagePolicy
{
    public function view(User $user, CarpoolCaseMessage $record): bool
    {
        return ! $record->internal && ($record->author_id === $user->id || $record->recipient_id === $user->id);
    }
}
