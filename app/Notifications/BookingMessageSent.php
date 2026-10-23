<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Il messaggio del locale a chi ha prenotato una data.
 *
 * Va a chi ha un account per email e nell'archivio in-app; a chi ha lasciato
 * solo un recapito al banco, per email soltanto. Il testo si rilegge dal
 * database al momento dell'invio: se intanto la prenotazione è stata
 * annullata, il messaggio non parte — riguardava un posto che non c'è più.
 */
class BookingMessageSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId, public int $bookingId)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', 'database'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $booking = Booking::query()->whereKey($this->bookingId)->active()->first();
        if ($booking === null || ! BookingMessage::query()->whereKey($this->messageId)->exists()) {
            return false;
        }

        return $notifiable instanceof AnonymousNotifiable
            ? $booking->user_id === null
            : ($notifiable instanceof User && $booking->user_id === $notifiable->id);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = BookingMessage::query()->with('occurrence.event.city')->findOrFail($this->messageId);
        $occurrence = $message->occurrence;
        $event = $occurrence?->event;
        $timezone = $event?->city->timezone ?? config('app.timezone');

        $mail = (new MailMessage)
            ->subject(__('ticketing.messages.mail_subject', ['venue' => $occurrence?->effectiveVenue()->name ?? config('app.name'), 'subject' => $message->subject]))
            ->greeting($message->subject)
            ->line(__('ticketing.messages.mail_intro', [
                'title' => $event->title ?? __('ticketing.title'),
                'date' => $occurrence?->starts_at->timezone($timezone)->format('d/m/Y H:i') ?? '',
            ]));

        foreach (preg_split('/\R{2,}/u', trim($message->body)) ?: [] as $paragraph) {
            $mail->line($paragraph);
        }

        if ($notifiable instanceof User) {
            $mail->action(__('ticketing.title'), route('tickets.index'));
        }

        return $mail->line(__('ticketing.messages.mail_footer', ['venue' => $occurrence?->effectiveVenue()->name ?? '']));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $message = BookingMessage::query()->find($this->messageId);

        return [
            'booking_id' => $this->bookingId,
            'message_id' => $this->messageId,
            'title' => $message?->subject,
            'message' => $message?->body,
            'url' => route('tickets.index'),
        ];
    }
}
