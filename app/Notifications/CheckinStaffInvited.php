<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\CheckinStaffInvitation;
use App\Models\EventOccurrence;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * L'invito al personale di ingresso di una data (D67). Parte verso
 * l'indirizzo scritto da chi gestisce la data, che sia registrato o no: il
 * testo è lo stesso, e dice come accettare in entrambi i casi.
 */
class CheckinStaffInvited extends Notification
{
    public function __construct(
        private readonly EventOccurrence $date,
        private readonly string $acceptUrl,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->date->loadMissing('event.city');
        $timezone = $this->date->event?->city->timezone ?? config('app.timezone');

        return (new MailMessage)
            ->subject(__('decision.staff_invitation.subject', ['event' => (string) $this->date->event?->title]))
            ->line(__('decision.staff_invitation.intro', [
                'event' => (string) $this->date->event?->title,
                'date' => $this->date->starts_at->timezone($timezone)->translatedFormat('j F Y, H:i'),
                'product' => (string) config('app.name'),
            ]))
            ->line(__('decision.staff_invitation.accept_hint', ['days' => CheckinStaffInvitation::EXPIRES_DAYS]))
            ->action(__('decision.staff_invitation.accept_action'), $this->acceptUrl)
            ->line(__('decision.staff_invitation.ignore'));
    }
}
