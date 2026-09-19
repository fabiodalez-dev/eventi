<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RideConversation;
use App\Models\RideMessage;
use App\Models\User;
use App\Services\Carpool\CarpoolAccess;

class RideMessagePolicy
{
    public function view(User $user, RideMessage $message): bool
    {
        $chat = RideConversation::where('conversation_id', $message->conversation_id)->first();

        return $chat && app(CarpoolAccess::class)->canReadChat($user, $chat);
    }
}
