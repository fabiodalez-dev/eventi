<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RideConversation;
use App\Models\User;
use App\Services\Carpool\CarpoolAccess;

final class RideConversationPolicy
{
    public function view(User $user, RideConversation $record): bool
    {
        return app(CarpoolAccess::class)->canReadChat($user, $record);
    }
}
