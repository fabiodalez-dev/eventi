<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * Uscire da soli da un locale (D64).
 *
 * Se un invito si accetta, da un locale si deve anche poter uscire senza
 * chiedere a chi ci ha invitato. L'unico limite è l'ultimo referente: un
 * locale senza nessuno che ne risponde resterebbe con una scheda che nessuno
 * può correggere, e chi deve lasciarlo lo chiede alla redazione, che decide
 * a chi passarlo.
 *
 * Il ruolo globale resta: come quando un referente toglie un collaboratore,
 * la persona compare fra gli account «senza locale» della redazione.
 */
final class LeaveVenueAction
{
    public function execute(Venue $venue, User $user): bool
    {
        return DB::transaction(function () use ($venue, $user): bool {
            /* Il blocco sulle righe dei referenti: due referenti che escono
               nello stesso istante lascerebbero il locale senza nessuno. */
            $owners = DB::table('venue_user')
                ->where('venue_id', $venue->getKey())
                ->whereNotNull('accepted_at')
                ->where('role', VenueRole::Owner->value)
                ->lockForUpdate()
                ->pluck('user_id')
                ->map(fn (mixed $id): int => (int) $id);

            $role = $user->venues()->whereKey($venue->getKey())->first()?->getRelationValue('pivot')?->getAttribute('role');

            if (! is_string($role) || ($role === VenueRole::Owner->value && $owners->count() <= 1)) {
                return false;
            }

            $user->venues()->detach($venue->getKey());

            SecurityLog::scrivi('uscita_dal_locale', $user, [
                'venue_id' => $venue->getKey(),
                'ruolo' => $role,
            ], causa: $user);

            return true;
        });
    }

    /** L'ultimo referente accettato del locale non può uscire da solo. */
    public function isLastOwner(Venue $venue, User $user): bool
    {
        return $user->isOwnerOfVenue((int) $venue->getKey())
            && $venue->owners()->count() <= 1;
    }
}
