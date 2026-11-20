<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Support\RequestMemo;

class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id;
    }

    public function checkIn(User $user, EventOccurrence $occurrence): bool
    {
        return $this->manage($user, $occurrence)
            || $occurrence->checkinStaff()->whereKey($user->id)->exists();
    }

    public function manage(User $user, EventOccurrence $occurrence): bool
    {
        if ($occurrence->event?->organizer?->managedBy($user)) {
            return true;
        }
        $venueId = $occurrence->effectiveVenue()?->id;

        /* I locali di chi guarda si leggono una volta per richiesta: una
           scheda con molte date chiede questa policy per ciascuna. */
        return $venueId !== null && ($user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value])
            || in_array($venueId, RequestMemo::remember('booking-policy:owned-venues:'.$user->id, fn (): array => $user->activeOwnedVenues()->pluck('venues.id')->map(fn (mixed $id): int => (int) $id)->all()), true));
    }
}
