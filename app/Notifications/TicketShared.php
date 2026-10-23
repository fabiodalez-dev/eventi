<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AdmissionStatus;
use App\Models\AdmissionTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Il biglietto che chi ha prenotato manda a un partecipante. Il testo dice
 * chi l'ha inviato e che l'indirizzo non è stato salvato: chi lo riceve non
 * deve chiedersi perché un servizio che non conosce gli scrive.
 */
class TicketShared extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $ticketId, public string $senderName, public string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return AdmissionTicket::query()->whereKey($this->ticketId)->where('status', AdmissionStatus::Valid)->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = AdmissionTicket::query()->with('booking.occurrence.event.city')->findOrFail($this->ticketId);
        $date = $ticket->booking?->occurrence;
        $timezone = $date?->event->city->timezone ?? config('app.timezone');

        return (new MailMessage)
            ->subject(__('ticketing.share.mail_subject', ['name' => $this->senderName, 'title' => $date?->event->title ?? __('ticketing.title')]))
            ->line(__('ticketing.share.mail_intro', ['name' => $this->senderName, 'attendee' => $ticket->attendee_name]))
            ->line(($date?->event->title ?? '').' · '.($date?->starts_at->timezone($timezone)->format('d/m/Y H:i') ?? '').' · '.($date?->effectiveVenue()->name ?? ''))
            ->action(__('ticketing.share.mail_action'), $this->url)
            ->line(__('ticketing.share.mail_privacy', ['name' => $this->senderName]));
    }
}
