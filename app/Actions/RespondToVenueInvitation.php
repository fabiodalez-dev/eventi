<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * La risposta di chi è stato invitato in un locale (D64): accettare o
 * rifiutare.
 *
 * Il collegamento del messaggio è firmato, scade dopo
 * `InviteVenueMemberAction::EXPIRES_DAYS` giorni e porta la data dell'invito:
 * un invito reinviato o ritirato rende inutile quello vecchio senza bisogno
 * di tenere un elenco di collegamenti bruciati. La rotta chiede anche di
 * essere entrati con l'account invitato: la firma dice che il collegamento è
 * arrivato nella casella, la sessione che chi risponde è il titolare
 * dell'account. Le due prove insieme sono ciò che l'aggancio immediato non
 * chiedeva.
 */
final class RespondToVenueInvitation
{
    public static function url(Venue $venue, User $user, CarbonInterface $invitedAt): string
    {
        return URL::temporarySignedRoute(
            'venue-invitations.show',
            CarbonImmutable::instance($invitedAt)->addDays(InviteVenueMemberAction::EXPIRES_DAYS),
            ['venue' => $venue->getKey(), 'user' => $user->getKey(), 'invitation' => $invitedAt->getTimestamp()],
        );
    }

    /**
     * L'invito in sospeso indicato dal collegamento, se c'è ancora ed è
     * proprio quello: un invito reinviato ha una data nuova, e il collegamento
     * precedente non lo trova più.
     */
    public function pending(Venue $venue, User $user, ?int $invitation = null, bool $lock = false): ?Pivot
    {
        $query = $user->venuesIncludingInvited()->whereKey($venue->getKey())->wherePivotNull('accepted_at');
        $pivot = ($lock ? $query->lockForUpdate() : $query)->first()?->getRelationValue('pivot');

        if (! $pivot instanceof Pivot) {
            return null;
        }

        if ($invitation !== null) {
            $invitedAt = $pivot->getAttribute('invited_at');

            if ($invitedAt === null || CarbonImmutable::parse((string) $invitedAt)->getTimestamp() !== $invitation) {
                return null;
            }
        }

        return $pivot;
    }

    public function accept(Venue $venue, User $user, ?int $invitation = null): bool
    {
        return DB::transaction(function () use ($venue, $user, $invitation): bool {
            $pivot = $this->pending($venue, $user, $invitation, lock: true);

            if ($pivot === null) {
                return false;
            }

            $this->markAccepted($venue, $user, $pivot, 'collegamento');

            return true;
        });
    }

    public function decline(Venue $venue, User $user, int $invitation): bool
    {
        return DB::transaction(function () use ($venue, $user, $invitation): bool {
            $pivot = $this->pending($venue, $user, $invitation, lock: true);

            if ($pivot === null) {
                return false;
            }

            $user->venuesIncludingInvited()->wherePivotNull('accepted_at')->detach($venue->getKey());

            SecurityLog::scrivi('invito_locale_rifiutato', $user, [
                'venue_id' => $venue->getKey(),
                'ruolo' => $pivot->getAttribute('role'),
            ], causa: $user);

            return true;
        });
    }

    /**
     * La password scelta dal collegamento accetta gli inviti che hanno creato
     * l'account (`accepts_on_password`). Gli altri inviti della stessa persona
     * restano da accettare: «password dimenticata» prova la casella, non dice
     * di sì a un locale che ha scritto dopo. Vale solo entro la scadenza
     * dell'invito: mesi dopo, chi usa «password dimenticata» per entrare nel
     * sito non deve trovarsi dentro un locale che aveva ignorato. Restituisce
     * quanti ne ha accettati.
     */
    public function acceptOnPassword(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $venues = $user->venuesIncludingInvited()
                ->wherePivotNull('accepted_at')
                ->wherePivot('accepts_on_password', true)
                ->wherePivot('invited_at', '>=', now()->subDays(InviteVenueMemberAction::EXPIRES_DAYS))
                ->lockForUpdate()
                ->get();

            foreach ($venues as $venue) {
                $pivot = $venue->getRelationValue('pivot');

                if ($pivot instanceof Pivot) {
                    $this->markAccepted($venue, $user, $pivot, 'password');
                }
            }

            return $venues->count();
        });
    }

    private function markAccepted(Venue $venue, User $user, Pivot $pivot, string $via): void
    {
        $role = VenueRole::from((string) $pivot->getAttribute('role'));

        $user->venuesIncludingInvited()->updateExistingPivot($venue->getKey(), [
            'accepted_at' => now(),
            'accepts_on_password' => false,
        ]);
        InviteVenueMemberAction::ensureGlobalRole($user, $role);

        SecurityLog::scrivi('invito_locale_accettato', $user, [
            'venue_id' => $venue->getKey(),
            'ruolo' => $role->value,
            'tramite' => $via,
        ], causa: $user);
    }
}
