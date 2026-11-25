<?php

declare(strict_types=1);

namespace App\Services\Ticketing;

use App\Enums\BookingFieldMode;
use App\Enums\UserRole;
use App\Http\Resources\V1\PosterResource;
use App\Models\Booking;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Models\User;
use App\Support\Description;
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
     * Cerca nelle date come il riepilogo del sito (`/gestione-biglietti`):
     * nel titolo dell'evento e nel nome del locale della data, o di quello
     * dell'evento se la data non ne ha uno suo.
     *
     * @param  Builder<EventOccurrence>  $query
     * @return Builder<EventOccurrence>
     */
    public function search(Builder $query, string $search): Builder
    {
        $like = '%'.addcslashes(trim($search), '%_\\').'%';

        return $query->where(fn (Builder $q) => $q
            ->whereHas('event', fn (Builder $event) => $event->where('title', 'like', $like))
            ->orWhereHas('venue', fn (Builder $venue) => $venue->where('name', 'like', $like))
            ->orWhere(fn (Builder $fallback) => $fallback->whereNull('venue_id')->whereHas('event.venue', fn (Builder $venue) => $venue->where('name', 'like', $like))));
    }

    /**
     * Una data da gestire, per l'app. `poster` ha la forma di
     * `EventResource.poster`; `counts` sono i numeri di `TicketingService::counts()`
     * e chi chiama un elenco li passa già calcolati per tutte le date insieme.
     *
     * In ogni riga, come nel riepilogo del sito: `booking_enabled` (le
     * prenotazioni della data sono accese), `ticketing_enabled` (il locale è
     * abilitato dalla redazione) ed `ended` (la data è finita: il sito toglie
     * i moduli). Nella scheda, solo a chi gestisce, anche `settings`: le
     * impostazioni delle prenotazioni così come sono salvate, per il modulo.
     *
     * @param  array{confirmed: int, checked_in: int, waitlisted: int, cancelled: int, no_show: int, capacity: int|null}|null  $counts
     * @return array<string, mixed>
     */
    public function date(EventOccurrence $date, User $user, bool $detail = false, ?array $counts = null): array
    {
        $manage = $user->can('manage', [Booking::class, $date]);
        $service = app(TicketingService::class);
        $data = ['id' => $date->id, 'title' => $date->event?->title, 'starts_at' => $date->starts_at->toIso8601String(),
            'venue' => $date->effectiveVenue()?->name, 'can_manage' => $manage,
            'can_check_in' => $user->can('checkIn', [Booking::class, $date]),
            'poster' => $date->event !== null ? PosterResource::toArray($date->event) : null,
            'counts' => $counts ?? $service->counts([$date])[$date->id],
            'booking_enabled' => (bool) $date->booking_enabled,
            'ticketing_enabled' => (bool) $date->effectiveVenue()?->ticketing_enabled,
            'ended' => $service->hasEnded($date)];
        if ($detail && $manage) {
            $data += ['practical_details' => (object) ($date->practical_details ?? []), 'cost_breakdown' => (object) ($date->cost_breakdown ?? []),
                'currency' => $date->event?->currency ?: 'EUR',
                'staff' => $this->staff($date),
                'statistics' => (object) $service->statistics($date),
                'availability' => $service->availability($date),
                'timezone' => $date->event?->city->timezone ?? config()->string('app.timezone'),
                'address' => $date->locationVenue()?->address,
                'settings' => $this->settings($date)];
        }

        return $data;
    }

    /**
     * Le impostazioni delle prenotazioni come le legge il modulo: le tre ore
     * sono quelle salvate (`null` = «all'inizio dell'evento», o «subito» per
     * l'apertura), i campi del prenotante ci sono tutti, anche quelli non
     * raccolti, e le istruzioni arrivano anche in testo semplice. Se sul sito
     * sono state scritte con grassetto o elenchi (`booking_instructions_rich`),
     * chi le cambia dall'app le riscrive in testo semplice.
     *
     * @return array<string, mixed>
     */
    public function settings(EventOccurrence $date): array
    {
        $instructions = $date->booking_instructions;

        return [
            'booking_enabled' => (bool) $date->booking_enabled,
            'booking_capacity' => $date->booking_capacity,
            'booking_limit' => $date->booking_limit,
            'booking_waitlist' => (bool) $date->booking_waitlist,
            'booking_opens_at' => $date->booking_opens_at?->toIso8601String(),
            'booking_closes_at' => $date->booking_closes_at?->toIso8601String(),
            'cancellation_closes_at' => $date->cancellation_closes_at?->toIso8601String(),
            'booking_instructions' => Description::plain($instructions),
            'booking_instructions_rich' => is_string($instructions) && $instructions !== strip_tags($instructions),
            'booking_fields' => array_replace(array_fill_keys(BookingForm::FIELDS, BookingFieldMode::Hidden->value), array_intersect_key((array) ($date->booking_fields ?? []), array_flip(BookingForm::FIELDS))),
            'booking_questions' => app(BookingForm::class)->questions($date),
            'limits' => [
                'booking_limit_max' => 20,
                'questions' => BookingForm::MAX_QUESTIONS,
                'question_options' => BookingForm::MAX_OPTIONS,
                'question_label_max' => 160,
                'instructions_max' => 3000,
            ],
        ];
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
