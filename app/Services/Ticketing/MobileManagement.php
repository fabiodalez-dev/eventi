<?php

declare(strict_types=1);

namespace App\Services\Ticketing;

use App\Enums\UserRole;
use App\Http\Resources\V1\PosterResource;
use App\Models\Booking;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class MobileManagement
{
    private function admin(User $user): bool
    {
        return $user->hasAnyRole([UserRole::Admin->value, UserRole::SuperAdmin->value]);
    }

    /** @return Builder<EventOccurrence> */
    public function dates(User $user): Builder
    {
        return EventOccurrence::query()->when(! $this->admin($user), fn ($query) => $query->where(fn ($allowed) => $allowed
            ->whereHas('checkinStaff', fn ($q) => $q->whereKey($user->id))
            ->orWhereIn('venue_id', $user->activeOwnedVenues()->select('venues.id'))
            ->orWhere(fn ($fallback) => $fallback->whereNull('venue_id')->whereHas('event', fn ($event) => $event->whereIn('venue_id', $user->activeOwnedVenues()->select('venues.id'))))
            ->orWhereHas('event', fn ($event) => $event->whereIn('organizer_id', $user->managedOrganizers()->select('organizers.id')))));
    }

    /**
     * Le campagne dei locali di cui è referente e delle organizzazioni che
     * gestisce. Per le organizzazioni vale la regola delle statistiche delle
     * sponsorizzazioni (`SponsorshipReport::ofSponsor`): eventi che firmano,
     * campagne pagate con una loro abilitazione o messe dalla redazione. Prima
     * l'elenco guardava solo i locali, mentre gli organizzatori comprano alla
     * pari (D70).
     *
     * @return Builder<Sponsorship>
     */
    public function campaigns(User $user): Builder
    {
        $venues = fn () => $user->activeOwnedVenues()->select('venues.id');
        $organizers = fn () => $user->managedOrganizers()->select('organizers.id');

        return Sponsorship::withTrashed()->when(! $this->admin($user), fn ($query) => $query->where(fn ($sponsor) => $sponsor
            ->where(fn ($venue) => $venue
                ->whereHas('event', fn ($event) => $event->whereIn('venue_id', $venues()))
                ->where(fn ($q) => $q->whereNull('sponsorship_grant_id')->orWhereHas('grant', fn ($g) => $g->whereIn('venue_id', $venues()))))
            ->orWhere(fn ($organizer) => $organizer
                ->whereHas('event', fn ($event) => $event->whereIn('organizer_id', $organizers()))
                ->where(fn ($q) => $q->whereNull('sponsorship_grant_id')->orWhereHas('grant', fn ($g) => $g->whereIn('organizer_id', $organizers()))))));
    }

    /**
     * Il personale di una data: chi ha accettato e gli inviti in attesa (D67).
     * Di tutti solo l'indirizzo, quello che chi gestisce ha scritto: il nome
     * dell'account non gli spetta, e un invito non dice se l'indirizzo è
     * registrato. `name` resta per le versioni dell'app che lo leggono; `id`
     * non identifica l'account, serve solo a distinguere le righe.
     *
     * @return list<array{id: int, name: string, email: string, status: string}>
     */
    public function staff(EventOccurrence $date): array
    {
        $active = $date->checkinStaff()->orderBy('users.email')->pluck('users.email')
            ->map(fn (string $email): array => ['email' => mb_strtolower($email), 'status' => 'accepted']);
        $pending = $date->staffInvitations()->orderBy('email')->pluck('email')
            ->map(fn (string $email): array => ['email' => $email, 'status' => 'pending']);

        return $active->concat($pending)->unique('email')->values()
            ->map(fn (array $row, int $index): array => ['id' => $index + 1, 'name' => $row['email'], 'email' => $row['email'], 'status' => $row['status']])
            ->all();
    }

    /**
     * Una data da gestire, per l'app. `poster` ha la forma di
     * `EventResource.poster`; `counts` sono i numeri di `TicketingService::counts()`
     * e chi chiama un elenco li passa già calcolati per tutte le date insieme.
     *
     * @param  array{confirmed: int, checked_in: int, waitlisted: int, cancelled: int, no_show: int, capacity: int|null}|null  $counts
     * @return array<string, mixed>
     */
    public function date(EventOccurrence $date, User $user, bool $detail = false, ?array $counts = null): array
    {
        $manage = $user->can('manage', [Booking::class, $date]);
        $data = ['id' => $date->id, 'title' => $date->event?->title, 'starts_at' => $date->starts_at->toIso8601String(),
            'venue' => $date->effectiveVenue()?->name, 'can_manage' => $manage,
            'can_check_in' => $user->can('checkIn', [Booking::class, $date]),
            'poster' => $date->event !== null ? PosterResource::toArray($date->event) : null,
            'counts' => $counts ?? app(TicketingService::class)->counts([$date])[$date->id]];
        if ($detail && $manage) {
            $data += ['practical_details' => (object) ($date->practical_details ?? []), 'cost_breakdown' => (object) ($date->cost_breakdown ?? []),
                'currency' => $date->event?->currency ?: 'EUR',
                'staff' => $this->staff($date),
                'statistics' => (object) app(TicketingService::class)->statistics($date),
                'availability' => app(TicketingService::class)->availability($date)];
        }

        return $data;
    }

    /** @param array<string, mixed> $details */
    public function updateDetails(EventOccurrence $date, User $actor, array $details): void
    {
        DB::transaction(function () use ($date, $actor, $details): void {
            $locked = EventOccurrence::query()->lockForUpdate()->findOrFail($date->id);
            $locked->update($details);
            activity('ticketing')->causedBy($actor)->performedOn($locked)->log('decision_details_updated');
        });
    }
}
