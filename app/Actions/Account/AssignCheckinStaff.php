<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\EventOccurrence;
use App\Models\User;

final class AssignCheckinStaff
{
    public function __invoke(EventOccurrence $date, string $email, bool $remove, User $actor): void
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $remove ? $date->checkinStaff()->detach($user->id) : $date->checkinStaff()->syncWithoutDetaching([$user->id]);
        activity('ticketing')->causedBy($actor)->performedOn($date)->withProperties(['staff_id' => $user->id])->log($remove ? 'staff_revoked' : 'staff_assigned');
    }
}
