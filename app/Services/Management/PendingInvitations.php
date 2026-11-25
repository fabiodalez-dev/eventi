<?php

declare(strict_types=1);

namespace App\Services\Management;

use App\Actions\Account\RespondToCheckinStaffInvitation;
use App\Actions\InviteVenueMemberAction;
use App\Enums\VenueRole;
use App\Models\CheckinStaffInvitation;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Support\Api\ApiDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Gli inviti che una persona ha ricevuto e non ha ancora deciso, per «I tuoi
 * inviti» dell'app (W-CO-07, W-TK-15).
 *
 * Sono gli stessi che i collegamenti delle email lascerebbero accettare:
 *
 * - in un locale, la riga di `venue_user` in sospeso per l'account, entro i
 *   `InviteVenueMemberAction::EXPIRES_DAYS` giorni dall'invito (o dal
 *   reinvio), cioè la scadenza della firma;
 * - al personale d'ingresso, l'invito all'indirizzo dell'account, entro la
 *   sua scadenza, su una data non ancora finita.
 *
 * Nell'app il collegamento non c'è: al suo posto la rotta chiede l'accesso
 * con l'indirizzo confermato (`RequireConfirmedAccount`). Così risponde chi
 * ha dimostrato la casella, come sul sito.
 */
final class PendingInvitations
{
    public function __construct(private readonly RespondToCheckinStaffInvitation $checkin) {}

    /** @return BelongsToMany<Venue, User> */
    public function venues(User $user): BelongsToMany
    {
        return $user->venuesIncludingInvited()
            ->wherePivotNull('accepted_at')
            ->wherePivot('invited_at', '>', now()->subDays(InviteVenueMemberAction::EXPIRES_DAYS));
    }

    /** @return Builder<CheckinStaffInvitation> */
    public function checkin(User $user): Builder
    {
        return $this->checkin->pendingFor($user);
    }

    /** Quanti inviti aspettano una risposta: la voce «I tuoi inviti» del profilo compare solo se sono più di zero. */
    public function count(User $user): int
    {
        return $this->venues($user)->count() + $this->checkin($user)->count();
    }

    /**
     * La risposta di `GET /me/invitations`.
     *
     * @return array{venues: list<array<string, mixed>>, checkin: list<array<string, mixed>>}
     */
    public function payload(User $user): array
    {
        $venues = $this->venues($user)->with('city')->orderByPivot('invited_at', 'desc')->get()
            ->map(fn (Venue $venue): array => $this->venue($venue))->values()->all();
        $checkin = $this->checkin($user)->with(['occurrence.event', 'occurrence.venue.city', 'occurrence.event.venue.city'])->orderByDesc('invited_at')->get()
            ->map(fn (CheckinStaffInvitation $invitation): array => $this->checkinInvitation($invitation))->values()->all();

        return ['venues' => $venues, 'checkin' => $checkin];
    }

    /** @return array<string, mixed> */
    private function venue(Venue $venue): array
    {
        $pivot = $venue->getRelationValue('pivot');
        $invitedAt = $pivot instanceof Pivot && $pivot->getAttribute('invited_at') !== null
            ? CarbonImmutable::parse((string) $pivot->getAttribute('invited_at'))
            : CarbonImmutable::now();
        $role = VenueRole::tryFrom($pivot instanceof Pivot ? (string) $pivot->getAttribute('role') : '') ?? VenueRole::Editor;
        $timezone = (string) ($venue->city->timezone ?? config('app.timezone'));

        return [
            'venue' => [
                'id' => (int) $venue->getKey(),
                'slug' => (string) $venue->slug,
                'name' => (string) $venue->name,
                'city' => $venue->city?->name,
            ],
            'role' => $role->value,
            'role_label' => $role->label(),
            'sent' => $invitedAt->getTimestamp(),
            'invited_at' => ApiDate::instant($invitedAt, $timezone),
            'expires_at' => ApiDate::instant($invitedAt->addDays(InviteVenueMemberAction::EXPIRES_DAYS), $timezone),
        ];
    }

    /** @return array<string, mixed> */
    private function checkinInvitation(CheckinStaffInvitation $invitation): array
    {
        /** @var EventOccurrence $date */
        $date = $invitation->occurrence;
        $venue = $date->venue ?? $date->event?->venue;
        $timezone = (string) ($venue?->city->timezone ?? config('app.timezone'));

        return [
            'id' => (int) $invitation->getKey(),
            'occurrence' => [
                'id' => (int) $date->getKey(),
                'title' => (string) $date->event?->title,
                'starts_at' => ApiDate::instant($date->starts_at, $timezone),
                'venue' => $venue?->name,
            ],
            'sent' => $invitation->invited_at->getTimestamp(),
            'invited_at' => ApiDate::instant($invitation->invited_at, $timezone),
            'expires_at' => ApiDate::instant($invitation->invited_at->addDays(CheckinStaffInvitation::EXPIRES_DAYS), $timezone),
        ];
    }
}
