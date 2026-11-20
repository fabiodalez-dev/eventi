<?php

declare(strict_types=1);

namespace App\Services\Ticketing;

use App\Http\Resources\V1\BookingResource;
use App\Models\AdmissionTicket;
use App\Support\Poster;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Il PDF di un biglietto: lo scarica chi ha prenotato e lo allegano le email
 * di conferma. Un posto solo, così il file è uguale da qualunque parte arrivi.
 */
final class TicketPdf
{
    public function render(AdmissionTicket $ticket): string
    {
        $ticket->loadMissing(['booking.tickets', 'booking.occurrence.venue', 'booking.occurrence.event.venue', 'booking.occurrence.event.city', 'booking.occurrence.event.organizer', 'booking.occurrence.event.media']);
        $booking = $ticket->booking;
        $event = $booking->occurrence?->event;

        return Pdf::loadView('ticketing.pdf', [
            'ticket' => $ticket,
            'booking' => BookingResource::toArray($booking),
            'poster' => $event !== null ? Poster::pdfDataUri($event) : null,
        ])->setOption('isRemoteEnabled', false)->output();
    }

    public function filename(AdmissionTicket $ticket): string
    {
        return 'inCitta-ticket-'.$ticket->id.'.pdf';
    }
}
