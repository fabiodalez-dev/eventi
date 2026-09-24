<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AdmissionStatus;
use App\Http\Resources\V1\BookingResource;
use App\Models\Booking;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $bookingId, public string $kind)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return User::query()->whereKey($notifiable->id)->exists()
            && Booking::query()->whereKey($this->bookingId)->where('user_id', $notifiable->id)->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = Booking::query()->with('occurrence.event')->find($this->bookingId);

        $mail = (new MailMessage)->subject(__('ticketing.mail.'.$this->kind))
            ->line(__('ticketing.mail.'.$this->kind))
            ->line($booking?->occurrence?->event->title ?? __('ticketing.title'))
            ->line(__('ticketing.mail.current_status'));

        /* Una promozione senza scadenza scritta è una scadenza che nessuno
           rispetta. La condizione guarda il dato e non il tipo di messaggio:
           il riepilogo richiesto a mano parte come «confermata», e legandosi
           al tipo diceva che il posto è confermato senza dire che va ancora
           confermato entro un'ora precisa. */
        if ($booking?->promotion_expires_at !== null) {
            $mail->line(__('ticketing.mail.promotion_deadline', [
                'scadenza' => $booking->promotion_expires_at->timezone($booking->occurrence?->event?->city->timezone ?? config('app.timezone'))->format('d/m/Y H:i'),
            ]));
        }

        $mail->action(__('ticketing.title'), route('tickets.index'));
        // Render at send-time: cancelled, waiting and expired QR must never be attached.
        if ($booking && in_array($this->kind, ['confirmed', 'promoted', 'changed'], true)) {
            foreach ($booking->tickets as $ticket) {
                if ($ticket->displayStatus() !== AdmissionStatus::Valid) {
                    continue;
                }
                $bytes = Pdf::loadView('ticketing.pdf', ['ticket' => $ticket, 'booking' => BookingResource::toArray($booking)])
                    ->setOption('isRemoteEnabled', false)->output();
                $mail->attachData($bytes, 'inCitta-ticket-'.$ticket->id.'.pdf', ['mime' => 'application/pdf']);
            }
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['booking_id' => $this->bookingId, 'message' => __('ticketing.mail.'.$this->kind), 'url' => route('tickets.index')];
    }
}
