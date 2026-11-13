<?php

declare(strict_types=1);

namespace App\Services\Ticketing;

use App\Enums\UserRole;
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

    /** @return Builder<Sponsorship> */
    public function campaigns(User $user): Builder
    {
        return Sponsorship::withTrashed()->when(! $this->admin($user), fn ($query) => $query
            ->whereHas('event', fn ($event) => $event->whereIn('venue_id', $user->activeOwnedVenues()->select('venues.id')))
            ->where(fn ($q) => $q->whereNull('sponsorship_grant_id')->orWhereHas('grant', fn ($g) => $g->whereIn('venue_id', $user->activeOwnedVenues()->select('venues.id')))));
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

    /** @return array<string, mixed> */
    public function date(EventOccurrence $date, User $user, bool $detail = false): array
    {
        $manage = $user->can('manage', [Booking::class, $date]);
        $data = ['id' => $date->id, 'title' => $date->event?->title, 'starts_at' => $date->starts_at->toIso8601String(),
            'venue' => $date->effectiveVenue()?->name, 'can_manage' => $manage,
            'can_check_in' => $user->can('checkIn', [Booking::class, $date])];
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
