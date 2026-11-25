<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Management;

use App\Enums\AdmissionStatus;
use App\Models\AdmissionTicket;
use App\Models\Booking;

/**
 * Una prenotazione vista da chi gestisce la data: l'elenco
 * `GET /management/dates/{data}/bookings` e la risposta dell'annullamento
 * da gestore. È la riga della pagina `/gestione-biglietti/{data}`.
 *
 * Il codice di un biglietto compare solo finché è valido: serve a registrare
 * l'ingresso dalla riga (`POST /ticketing/{data}/check-in`), come il
 * pulsante della pagina web. Chi può solo fare il controllo ingressi non
 * arriva qui: ha l'elenco di `manifest`, con le impronte e mai i codici.
 */
final class ManagedBookingResource
{
    /**
     * La prenotazione deve avere `user` e `tickets` caricati, e la sua data
     * legata (`setRelation('occurrence', …)`): lo stato mostrato di un
     * biglietto la guarda.
     *
     * @return array<string, mixed>
     */
    public static function toArray(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'status' => $booking->status->value,
            'created_at' => $booking->created_at?->toIso8601String(),
            'staff_booking' => $booking->isStaffBooking(),
            'email' => $booking->contactEmail(),
            'booker' => $booking->booker_data,
            'answers' => array_map(fn (array $answer): array => ['question' => (string) $answer['question'], 'answer' => (string) $answer['answer']], (array) ($booking->booking_answers ?? [])),
            'privacy_accepted_at' => $booking->privacy_accepted_at?->toIso8601String(),
            'cancellation_reason' => $booking->cancellation_reason,
            'tickets_count' => $booking->tickets->count(),
            'tickets' => $booking->tickets->map(fn (AdmissionTicket $ticket): array => [
                'id' => $ticket->id,
                'attendee_name' => $ticket->attendee_name,
                'first_name' => $ticket->first_name,
                'last_name' => $ticket->last_name,
                'status' => $ticket->displayStatus()->value,
                'checked_in_at' => $ticket->checked_in_at?->toIso8601String(),
                'code' => $ticket->displayStatus() === AdmissionStatus::Valid ? $ticket->code : null,
            ])->values()->all(),
        ];
    }
}
