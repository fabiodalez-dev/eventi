<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AdmissionStatus;
use App\Http\Resources\V1\BookingResource;
use App\Models\Booking;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * La conferma a chi ha prenotato al banco o al telefono e ha lasciato un
 * indirizzo. Non ha un account, quindi non ha «i miei biglietti»: i biglietti
 * viaggiano in allegato e la mail dice dove andare e quando.
 */
class GuestBookingConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $bookingId)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return Booking::query()->whereKey($this->bookingId)->whereNull('user_id')->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = Booking::query()->with(['occurrence.event.city', 'tickets'])->findOrFail($this->bookingId);
        $occurrence = $booking->occurrence;
        $timezone = $occurrence?->event->city->timezone ?? config('app.timezone');

        $mail = (new MailMessage)
            ->subject(__('ticketing.mail.confirmed'))
            ->line(__('ticketing.guest.mail_line', ['title' => $occurrence?->event->title ?? __('ticketing.title')]))
            ->line(($occurrence?->starts_at->timezone($timezone)->format('d/m/Y H:i') ?? '').' · '.($occurrence?->effectiveVenue()->name ?? ''))
            ->line(__('ticketing.guest.mail_hint'));

        foreach ($booking->tickets as $ticket) {
            if ($ticket->displayStatus() !== AdmissionStatus::Valid) {
                continue;
            }
            $bytes = Pdf::loadView('ticketing.pdf', ['ticket' => $ticket, 'booking' => BookingResource::toArray($booking)])
                ->setOption('isRemoteEnabled', false)->output();
            $mail->attachData($bytes, 'inCitta-ticket-'.$ticket->id.'.pdf', ['mime' => 'application/pdf']);
        }

        return $mail;
    }
}
