<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\EventOccurrence;
use App\Models\User;

/**
 * Aggiunge o toglie una persona dal personale di ingresso di una data.
 *
 * Un indirizzo che non corrisponde a nessun account non fa nulla e non dà
 * errore: chi gestisce la data non deve poter usare questo campo per scoprire
 * chi è registrato.
 */
final class AssignCheckinStaff
{
    public function __invoke(EventOccurrence $date, string $email, bool $remove, User $actor): void
    {
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            return;
        }
        $remove ? $date->checkinStaff()->detach($user->id) : $date->checkinStaff()->syncWithoutDetaching([$user->id]);
        activity('ticketing')->causedBy($actor)->performedOn($date)->withProperties(['staff_id' => $user->id])->log($remove ? 'staff_revoked' : 'staff_assigned');
    }
}
