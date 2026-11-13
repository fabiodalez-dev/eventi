<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\CheckinStaffInvitation;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Notifications\CheckinStaffInvited;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Invita una persona al personale di ingresso di una data, o la toglie (D67).
 *
 * Aggiungere non aggancia nessun account: manda un invito all'indirizzo
 * scritto, sempre, che sia registrato o no, e la data mostra quell'indirizzo
 * «in attesa». Entra nel personale solo chi apre il collegamento ed accetta
 * con l'account di quell'indirizzo. Così chi gestisce la data non scopre chi
 * è registrato: né dalla risposta, né dalla lista, né dai tempi.
 */
final class AssignCheckinStaff
{
    /** Un invito ancora in attesa si rimanda al massimo una volta ogni ora. */
    private const RESEND_AFTER_MINUTES = 60;

    /**
     * Mail d'invito per indirizzo in un giorno, su tutte le date. Il tetto
     * non sta sulla riga dell'invito, che sparisce quando lo si ritira o lo
     * si rifiuta: «invita, ritira, invita» non deve diventare un modo di
     * riempire la casella di qualcuno.
     */
    public const MAILS_PER_RECIPIENT_PER_DAY = 3;

    /** Mail d'invito che una persona può far partire in un giorno. */
    public const MAILS_PER_ACTOR_PER_DAY = 60;

    public static function acceptUrl(CheckinStaffInvitation $invitation): string
    {
        return URL::temporarySignedRoute(
            'checkin-staff-invitations.show',
            $invitation->invited_at->addDays(CheckinStaffInvitation::EXPIRES_DAYS),
            ['invitation' => $invitation->getKey(), 'sent' => $invitation->invited_at->getTimestamp()],
        );
    }

    public function __invoke(EventOccurrence $date, string $email, bool $remove, User $actor): void
    {
        $email = mb_strtolower(trim($email));

        $remove ? $this->remove($date, $email, $actor) : $this->invite($date, $email, $actor);
    }

    private function invite(EventOccurrence $date, string $email, User $actor): void
    {
        // Chi è già nel personale non riceve un altro invito: per la data è
        // già un indirizzo noto, e la lista lo mostra come attivo.
        if ($date->checkinStaff()->whereRaw('LOWER(users.email) = ?', [$email])->exists()) {
            return;
        }

        // Il tetto di chi invita si controlla prima di creare l'invito, così non resta una riga senza mail.
        $actorKey = 'checkin-invites:actor:'.$actor->id;
        if (RateLimiter::tooManyAttempts($actorKey, self::MAILS_PER_ACTOR_PER_DAY)) {
            throw ValidationException::withMessages(['email' => __('decision.staff_daily_limit')]);
        }

        $invitation = DB::transaction(function () use ($date, $email, $actor): ?CheckinStaffInvitation {
            $existing = $date->staffInvitations()->where('email', $email)->lockForUpdate()->first();

            if ($existing instanceof CheckinStaffInvitation) {
                if ($existing->invited_at->isAfter(now()->subMinutes(self::RESEND_AFTER_MINUTES))) {
                    return null;
                }
                $existing->forceFill(['invited_at' => CarbonImmutable::now(), 'invited_by_id' => $actor->id])->save();

                return $existing;
            }

            if ($date->staffInvitations()->count() >= CheckinStaffInvitation::MAX_PENDING) {
                throw ValidationException::withMessages(['email' => __('decision.staff_too_many', ['max' => CheckinStaffInvitation::MAX_PENDING])]);
            }

            return $date->staffInvitations()->create(['email' => $email, 'invited_by_id' => $actor->id, 'invited_at' => CarbonImmutable::now()]);
        });

        if (! $invitation instanceof CheckinStaffInvitation) {
            return;
        }

        // Oltre il tetto per indirizzo l'invito resta in attesa ma la mail non
        // parte, senza dirlo: un errore qui direbbe che l'indirizzo è stato
        // invitato altrove.
        $recipientKey = 'checkin-invites:to:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($recipientKey, self::MAILS_PER_RECIPIENT_PER_DAY)) {
            return;
        }
        RateLimiter::hit($actorKey, 86400);
        RateLimiter::hit($recipientKey, 86400);

        Notification::route('mail', $email)->notify(new CheckinStaffInvited($date, self::acceptUrl($invitation)));
        activity('ticketing')->causedBy($actor)->performedOn($date)->withProperties(['invitation_id' => $invitation->id])->log('staff_invited');
    }

    private function remove(EventOccurrence $date, string $email, User $actor): void
    {
        $date->staffInvitations()->where('email', $email)->delete();
        $ids = $date->checkinStaff()->whereRaw('LOWER(users.email) = ?', [$email])->pluck('users.id')->all();
        if ($ids === []) {
            return;
        }
        $date->checkinStaff()->detach($ids);
        activity('ticketing')->causedBy($actor)->performedOn($date)->withProperties(['staff_id' => $ids[0]])->log('staff_revoked');
    }
}
