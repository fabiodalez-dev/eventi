<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\CheckinStaffInvitation;
use App\Models\EventOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La risposta di chi è stato invitato al personale di ingresso di una data
 * (D67): accettare o rifiutare.
 *
 * Era dentro `CheckinStaffInvitationController`; la chiamano la pagina del
 * collegamento firmato e l'API dell'app («I tuoi inviti»), così la regola
 * resta una. La prova che risponde il titolare dell'indirizzo la porta chi
 * chiama: sul sito il collegamento arrivato nella casella più la sessione,
 * nell'app l'accesso con l'indirizzo confermato e uguale a quello invitato.
 */
final class RespondToCheckinStaffInvitation
{
    /**
     * Gli inviti ancora valutabili per l'indirizzo della persona: non
     * scaduti (la stessa scadenza del collegamento) e su date non finite.
     *
     * @return Builder<CheckinStaffInvitation>
     */
    public function pendingFor(User $user): Builder
    {
        return CheckinStaffInvitation::query()
            ->where('email', mb_strtolower((string) $user->email))
            ->where('invited_at', '>', now()->subDays(CheckinStaffInvitation::EXPIRES_DAYS))
            ->whereHas('occurrence', fn (Builder $date): Builder => $date->whereRaw('COALESCE(effective_ends_at, ends_at, starts_at) >= ?', [now()]));
    }

    /** Se la data dell'invito è già finita: un invito così non si accetta più. */
    public static function dateIsOver(EventOccurrence $date): bool
    {
        return ($date->effective_ends_at ?? $date->ends_at ?? $date->starts_at)->isPast();
    }

    /**
     * Accetta (la persona entra nel personale della data) o rifiuta (l'invito
     * sparisce). Dice se la persona è entrata: `false` per un rifiuto, o per
     * un invito che nel frattempo è stato ritirato o già usato.
     */
    public function respond(CheckinStaffInvitation $invitation, EventOccurrence $date, User $user, bool $accept): bool
    {
        return DB::transaction(function () use ($invitation, $user, $date, $accept): bool {
            $locked = CheckinStaffInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();
            if (! $locked instanceof CheckinStaffInvitation) {
                return false;
            }
            $locked->delete();
            if (! $accept) {
                return false;
            }
            $date->checkinStaff()->syncWithoutDetaching([$user->id]);
            activity('ticketing')->causedBy($user)->performedOn($date)->withProperties(['staff_id' => $user->id])->log('staff_assigned');

            return true;
        });
    }
}
