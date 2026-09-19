<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RideOffer;
use App\Models\User;
use App\Services\Carpool\CarpoolAccess;

final class RideOfferPolicy
{
    public function view(User $user, RideOffer $record): bool
    {
        return app(CarpoolAccess::class)->canViewOffer($user, $record);
    }
}
