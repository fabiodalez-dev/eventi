<?php

declare(strict_types=1);

namespace App\Http\Requests\Ticketing;

use App\Models\Booking;
use App\Models\EventOccurrence;

/**
 * L'annullamento a nome del locale dall'app
 * (`/management/dates/{data}/bookings/{prenotazione}/cancel`).
 *
 * Il permesso (`BookingPolicy::manage` sulla data) si chiede qui, prima della
 * validazione e prima di cercare la prenotazione: chi non gestisce la data
 * riceve sempre la stessa risposta, qualunque numero di prenotazione mandi.
 */
final class DateBookingCancelRequest extends CancelRequest
{
    public function authorize(): bool
    {
        $occurrence = $this->route('occurrence');

        return $occurrence instanceof EventOccurrence
            && $this->user()?->can('manage', [Booking::class, $occurrence]) === true;
    }
}
