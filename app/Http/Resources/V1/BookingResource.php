<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Enums\AdmissionStatus;
use App\Models\Booking;

final class BookingResource
{
    /** @return array<string, mixed> */
    public static function toArray(Booking $booking): array
    {
        $date = $booking->occurrence;
        $event = $date?->event()->withTrashed()->first();

        return [
            'id' => $booking->id,
            'occurrence_id' => $booking->occurrence_id,
            'title' => $event->title ?? __('ticketing.unavailable'),
            'event_slug' => $event?->slug,
            'venue' => $date?->effectiveVenue()?->name,
            'address' => $date?->effectiveVenue()?->address,
            'starts_at' => $date?->starts_at?->toIso8601String(),
            'status' => $booking->status->value,
            'instructions' => $date?->booking_instructions,
            'cancellation_reason' => $booking->cancellation_reason,
            'booker' => $booking->booker_data,
            'privacy_accepted_at' => $booking->privacy_accepted_at?->toIso8601String(),
            'can_cancel' => $date && now()->lt($date->cancellation_closes_at ?? $date->starts_at),
            'tickets' => $booking->tickets->map(fn ($ticket) => [
                'id' => $ticket->id, 'attendee_name' => $ticket->attendee_name,
                'first_name' => $ticket->first_name, 'last_name' => $ticket->last_name,
                'status' => $ticket->displayStatus()->value,
                'qr_payload' => $ticket->displayStatus() === AdmissionStatus::Valid ? $ticket->code : null,
                'checked_in_at' => $ticket->checked_in_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
