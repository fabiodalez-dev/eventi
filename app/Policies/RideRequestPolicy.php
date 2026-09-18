<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RideRequest;
use App\Models\User;
use App\Services\Carpool\CarpoolAccess;

final class RideRequestPolicy
{
    public function view(User $user, RideRequest $record): bool
    {
        return app(CarpoolAccess::class)->participant($user, $record);
    }
}
