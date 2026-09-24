<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Models\AdmissionTicket;
use App\Models\Booking;
use App\Models\Sponsorship;
use Carbon\CarbonImmutable;

final class CampaignEconomics
{
    /** @return array{spend_cents: int|null, currency: string, bookings: int|null, attendances: int|null, per_booking_cents: int|null, per_attendance_cents: int|null} */
    public function for(Sponsorship $campaign): array
    {
        // A grant pays for multiple campaigns. Never invent an allocation of that spend.
        $spend = $campaign->sponsorship_grant_id === null ? $campaign->amount_cents : null;
        $end = $campaign->ends_at->min(CarbonImmutable::now());
        $dateFilter = fn ($q) => $q->where('event_id', $campaign->event_id)
            ->where(fn ($q) => $q->whereNull('venue_id')->orWhere('venue_id', $campaign->event?->venue_id));
        $bookingQuery = Booking::query()->where('status', BookingStatus::Confirmed)
            ->whereBetween('created_at', [$campaign->starts_at, $end])
            ->whereHas('occurrence', $dateFilter);
        $bookings = (clone $bookingQuery)->count();
        $bookingAccounts = (clone $bookingQuery)->distinct()->count('user_id');
        $attendances = AdmissionTicket::query()->where('status', AdmissionStatus::CheckedIn)
            ->whereBetween('checked_in_at', [$campaign->starts_at, $end])
            ->whereHas('booking.occurrence', $dateFilter)->count();

        $attendanceAccounts = Booking::query()->whereHas('occurrence', $dateFilter)
            ->whereHas('tickets', fn ($q) => $q->where('status', AdmissionStatus::CheckedIn)->whereBetween('checked_in_at', [$campaign->starts_at, $end]))
            ->distinct()->count('user_id');

        // Suppress small samples even though the UI shows no individual identities.
        return [
            'spend_cents' => $spend, 'currency' => $campaign->currency ?: 'EUR',
            'bookings' => $bookingAccounts >= 5 ? $bookings : null,
            'attendances' => $attendanceAccounts >= 5 ? $attendances : null,
            'per_booking_cents' => $spend !== null && $bookingAccounts >= 5 ? (int) round($spend / $bookings) : null,
            'per_attendance_cents' => $spend !== null && $attendanceAccounts >= 5 ? (int) round($spend / $attendances) : null,
        ];
    }
}
