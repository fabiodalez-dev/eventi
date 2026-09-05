<?php

declare(strict_types=1);

namespace App\Services\Ticketing;

use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Models\AdmissionTicket;
use App\Models\Booking;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Notifications\BookingChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TicketingService
{
    public function activeBooking(User $user, EventOccurrence $date): ?Booking
    {
        return Booking::query()->active()->where('user_id', $user->id)
            ->where('occurrence_id', $date->id)->latest('id')->first();
    }

    /** @return array<string, int> */
    public function statistics(EventOccurrence $date): array
    {
        return AdmissionTicket::query()->whereHas('booking', fn ($query) => $query->where('occurrence_id', $date->id))
            ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')
            ->map(fn ($total): int => (int) $total)->all();
    }

    /** @return array<string, mixed> */
    public function availability(EventOccurrence $date): array
    {
        $used = $this->occupied($date);
        $remaining = $date->booking_capacity === null ? null : max(0, $date->booking_capacity - $used);
        $open = $this->isOpen($date);

        return [
            'enabled' => (bool) ($date->booking_enabled && $date->event?->venue?->ticketing_enabled),
            'open' => $open,
            'capacity' => $date->booking_capacity,
            'remaining' => $remaining,
            'limit_per_account' => $date->booking_limit,
            'waitlist' => (bool) $date->booking_waitlist,
            'opens_at' => $date->booking_opens_at?->toIso8601String(),
            'closes_at' => ($date->booking_closes_at ?? $date->starts_at)->toIso8601String(),
            'cancellation_closes_at' => ($date->cancellation_closes_at ?? $date->starts_at)->toIso8601String(),
            'instructions' => $date->booking_instructions,
            'booker_fields' => app(BookingForm::class)->fields($date),
            'privacy_url' => url('/pagine/privacy'),
        ];
    }

    /** @param list<string|array{first_name: string, last_name: string}> $names
     * @param  array<string, mixed>  $booker
     */
    public function reserve(User $user, EventOccurrence $date, array $names, string $key, bool $waitlist, array $booker = []): Booking
    {
        $names = array_map(fn ($name) => is_array($name) ? array_map('trim', $name) : trim($name), $names);
        ksort($booker);
        // Preserve the digest of already-issued legacy retries.
        $payload = [$date->id, $names, $waitlist];
        if ($booker !== []) {
            $payload[] = $booker;
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $date, $names, $key, $hash, $waitlist, $booker): Booking {
            // Serialise retries even when a client reuses its key for another date.
            $account = User::query()->lockForUpdate()->findOrFail($user->id);
            // The occurrence lock must precede ANY consistent read. Under
            // InnoDB REPEATABLE READ an earlier snapshot could miss seats
            // committed by the request we just waited for.
            $date = EventOccurrence::query()->lockForUpdate()->findOrFail($date->id);
            $existing = Booking::query()->where('user_id', $account->id)->where('request_key', $key)->first();
            if ($existing) {
                $this->ensure(hash_equals($existing->request_hash, $hash), 'retry_conflict');

                return $existing->load('tickets');
            }
            $this->ensure($this->activeBooking($account, $date) === null, 'already_booked');
            $this->ensure($this->isOpen($date), 'closed');
            $details = app(BookingForm::class)->validate($date, $booker);
            // Resume queued groups first when a previously closed window reopens.
            $this->promote($date);
            $this->ensure(count($names) > 0 && count($names) <= $date->booking_limit, 'account_limit');
            $already = AdmissionTicket::query()->whereHas('booking', fn ($q) => $q->where('occurrence_id', $date->id)->where('user_id', $user->id))
                ->where('status', '!=', AdmissionStatus::Cancelled)->count();
            $this->ensure($already + count($names) <= $date->booking_limit, 'account_limit');
            $hasQueue = Booking::query()->where('occurrence_id', $date->id)->where('status', BookingStatus::Waitlisted)->exists();
            $full = $hasQueue || ($date->booking_capacity !== null && $this->occupied($date) + count($names) > $date->booking_capacity);
            $this->ensure(! $full || ($waitlist && $date->booking_waitlist), 'full');
            $booking = Booking::query()->create([
                'occurrence_id' => $date->id, 'user_id' => $user->id, 'request_key' => $key,
                'request_hash' => $hash, 'status' => $full ? BookingStatus::Waitlisted : BookingStatus::Confirmed,
                'booker_data' => $details ?: null,
                'privacy_accepted_at' => now(),
                'privacy_version' => BookingForm::PRIVACY_VERSION,
            ]);
            foreach ($names as $name) {
                $booking->tickets()->create([
                    'attendee_name' => is_array($name) ? $name['first_name'].' '.$name['last_name'] : $name,
                    'first_name' => is_array($name) ? $name['first_name'] : null,
                    'last_name' => is_array($name) ? $name['last_name'] : null,
                    'code' => Str::random(64),
                    'status' => $full ? AdmissionStatus::Waitlisted : AdmissionStatus::Valid,
                ]);
            }
            $this->notify($booking, $full ? 'waitlisted' : 'confirmed');
            $this->audit($booking, 'reserved', $user);

            return $booking->load('tickets');
        }, 3);
    }

    public function cancel(Booking $booking, User $actor, ?int $ticketId = null, bool $staff = false, ?string $reason = null): Booking
    {
        return DB::transaction(function () use ($booking, $actor, $ticketId, $staff, $reason): Booking {
            $date = EventOccurrence::withTrashed()->lockForUpdate()->findOrFail($booking->occurrence_id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $tickets = $booking->tickets()->when($ticketId !== null, fn ($q) => $q->whereKey($ticketId))->get();
            abort_if($tickets->isEmpty(), 404);
            $active = $tickets->where('status', '!=', AdmissionStatus::Cancelled);
            if ($active->isEmpty()) {
                return $booking->load('tickets');
            }
            if (! $staff) {
                $this->ensure(now()->lt($date->cancellation_closes_at ?? $date->starts_at), 'cancellation_closed');
                $this->ensure(! $active->contains('status', AdmissionStatus::CheckedIn), 'already_used');
            }
            foreach ($active as $ticket) {
                $ticket->update(['status' => AdmissionStatus::Cancelled, 'cancelled_at' => now()]);
            }
            if (! $booking->tickets()->where('status', '!=', AdmissionStatus::Cancelled)->exists()) {
                $booking->update(['status' => BookingStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            }
            $this->audit($booking, 'cancelled', $actor);
            $this->notify($booking, 'cancelled');
            $this->promote($date);

            return $booking->load('tickets');
        }, 3);
    }

    public function checkIn(EventOccurrence $date, string $code, User $actor): AdmissionTicket
    {
        return DB::transaction(function () use ($date, $code, $actor): AdmissionTicket {
            $date = EventOccurrence::query()->lockForUpdate()->findOrFail($date->id);
            $this->ensure($this->eventValid($date), 'event_cancelled');
            $this->ensure(now()->between($date->doors_at ?? $date->starts_at->copy()->subHours(3), $date->effective_ends_at ?? $date->starts_at->copy()->addHours(6)), 'checkin_closed');
            $ticket = AdmissionTicket::query()->where('code', $code)
                ->whereHas('booking', fn ($q) => $q->where('occurrence_id', $date->id)->where('status', BookingStatus::Confirmed))
                ->lockForUpdate()->first();
            $this->ensure($ticket !== null, 'invalid_qr');
            $this->ensure($ticket->status !== AdmissionStatus::CheckedIn, 'already_used');
            $this->ensure($ticket->status === AdmissionStatus::Valid, 'invalid_qr');
            $ticket->update(['status' => AdmissionStatus::CheckedIn, 'checked_in_at' => now(), 'checked_in_by' => $actor->id]);
            $this->audit($ticket->booking, 'checked_in:'.$ticket->id, $actor);

            return $ticket;
        }, 3);
    }

    /** @param array<string, mixed> $settings */
    public function configure(EventOccurrence $date, array $settings, User $actor): void
    {
        DB::transaction(function () use ($date, $settings, $actor): void {
            $date = EventOccurrence::query()->lockForUpdate()->findOrFail($date->id);
            $this->ensure((bool) $date->event?->venue?->ticketing_enabled, 'disabled');
            $capacity = $settings['booking_capacity'] ?? null;
            $this->ensure($capacity === null || $capacity >= $this->occupied($date), 'capacity_too_low');
            $date->update($settings);
            activity('ticketing')->causedBy($actor)->performedOn($date)->log('configured');
            $this->promote($date);
        }, 3);
    }

    public function cancelDate(EventOccurrence $date): void
    {
        DB::transaction(function () use ($date): void {
            $date = EventOccurrence::withTrashed()->lockForUpdate()->find($date->id);
            if (! $date) {
                return;
            }
            Booking::query()->where('occurrence_id', $date->id)->where('status', '!=', BookingStatus::Cancelled)
                ->chunkById(100, function ($bookings): void {
                    foreach ($bookings as $booking) {
                        $booking->tickets()->where('status', '!=', AdmissionStatus::Cancelled)->update(['status' => AdmissionStatus::Cancelled->value, 'cancelled_at' => now()]);
                        $booking->update(['status' => BookingStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => __('ticketing.errors.event_cancelled')]);
                        $this->notify($booking, 'event_cancelled');
                    }
                });
        }, 3);
    }

    public function announceChange(EventOccurrence $date): void
    {
        Booking::query()->where('occurrence_id', $date->id)->where('status', '!=', BookingStatus::Cancelled)
            ->each(fn (Booking $booking) => $this->notify($booking, 'changed'));
    }

    public function promoteWaitingLists(): void
    {
        Booking::query()->where('status', BookingStatus::Waitlisted)->select('occurrence_id')->distinct()
            ->orderBy('occurrence_id')->get()->each(function (Booking $booking): void {
                DB::transaction(function () use ($booking): void {
                    $date = EventOccurrence::query()->lockForUpdate()->find($booking->occurrence_id);
                    if ($date) {
                        $this->promote($date);
                    }
                }, 3);
            });
    }

    public function eraseUser(User $user): void
    {
        $dateIds = Booking::query()->where('user_id', $user->id)->distinct()->orderBy('occurrence_id')->pluck('occurrence_id');
        foreach ($dateIds as $id) {
            $date = EventOccurrence::withTrashed()->lockForUpdate()->find($id);
            Booking::query()->where('user_id', $user->id)->where('occurrence_id', $id)->delete();
            if ($date) {
                $this->promote($date);
            }
        }
    }

    private function promote(EventOccurrence $date): void
    {
        if (! $this->isOpen($date)) {
            return;
        }
        while ($booking = Booking::query()->where('occurrence_id', $date->id)->where('status', BookingStatus::Waitlisted)->oldest('id')->first()) {
            $count = $booking->tickets()->where('status', AdmissionStatus::Waitlisted)->count();
            if ($date->booking_capacity !== null && $this->occupied($date) + $count > $date->booking_capacity) {
                break;
            }
            $booking->update(['status' => BookingStatus::Confirmed]);
            $booking->tickets()->where('status', AdmissionStatus::Waitlisted)->update(['status' => AdmissionStatus::Valid->value]);
            $this->notify($booking, 'promoted');
        }
    }

    private function occupied(EventOccurrence $date): int
    {
        return AdmissionTicket::query()->whereHas('booking', fn ($q) => $q->where('occurrence_id', $date->id))
            ->whereIn('status', [AdmissionStatus::Valid, AdmissionStatus::CheckedIn])->count();
    }

    private function eventValid(EventOccurrence $date): bool
    {
        return ! $date->trashed() && $date->event?->status === EventStatus::Published
            && ! $date->event->venue?->status?->isProvvedimento()
            && in_array($date->status, [OccurrenceStatus::Scheduled, OccurrenceStatus::SoldOut, OccurrenceStatus::Moved], true);
    }

    private function isOpen(EventOccurrence $date): bool
    {
        return $this->eventValid($date) && $date->status !== OccurrenceStatus::SoldOut
            && $date->booking_enabled && $date->event?->venue?->ticketing_enabled
            && ($date->booking_opens_at === null || now()->gte($date->booking_opens_at))
            && now()->lt($date->booking_closes_at ?? $date->starts_at)
            && now()->lt($date->starts_at);
    }

    private function ensure(bool $condition, string $key): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['ticketing' => __('ticketing.errors.'.$key)]);
        }
    }

    private function notify(Booking $booking, string $kind): void
    {
        $booking->user?->notify(new BookingChanged($booking->id, $kind));
    }

    private function audit(Booking $booking, string $action, User $actor): void
    {
        activity('ticketing')->causedBy($actor)->performedOn($booking)->log($action);
    }
}
