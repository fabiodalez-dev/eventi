<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\EventOccurrence;
use App\Models\User;

class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id;
    }

    public function manage(User $user, EventOccurrence $occurrence): bool
    {
        if ($occurrence->event?->organizer?->managedBy($user)) {
            return true;
        }
        $venueId = $occurrence->effectiveVenue()?->id;

        return $venueId !== null && ($user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value])
            || $user->ownedVenues()->whereKey($venueId)->exists());
    }
}
