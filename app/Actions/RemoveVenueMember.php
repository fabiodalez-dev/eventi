<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * Il referente toglie un collaboratore dal locale (§10.6).
 *
 * Era dentro la pagina «Collaboratori» del pannello; adesso la chiamano la
 * pagina e l'API dell'app, così la regola resta una:
 *
 * - si toglie solo chi ha accettato: un invito in attesa si ritira
 *   (`InviteVenueMemberAction::withdraw`), non si «rimuove»;
 * - mai un referente: chi risponde del locale lo decide la redazione, e
 *   nessuno può togliere se stesso lasciando il locale senza nessuno.
 *
 * L'account resta, con il suo ruolo globale: la persona compare fra gli
 * account «senza locale» della redazione, come quando esce da sola
 * (`LeaveVenueAction`). Gli eventi che ha inserito restano al locale.
 */
final class RemoveVenueMember
{
    /** Dice se la persona è stata tolta; `false` per un referente, un invito in attesa o chi non è del locale. */
    public function execute(Venue $venue, User $member, ?User $causa = null): bool
    {
        return DB::transaction(function () use ($venue, $member, $causa): bool {
            $row = DB::table('venue_user')
                ->where('venue_id', $venue->getKey())
                ->where('user_id', $member->getKey())
                ->lockForUpdate()
                ->first(['role', 'accepted_at']);

            if ($row === null || $row->accepted_at === null || $row->role === VenueRole::Owner->value) {
                return false;
            }

            $member->venues()->detach($venue->getKey());

            SecurityLog::scrivi('accesso_locale_revocato', $member, [
                'venue_id' => $venue->getKey(),
                'ruolo' => $row->role,
            ], causa: $causa);

            return true;
        });
    }
}
