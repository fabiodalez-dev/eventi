<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use App\Models\VenueApplication;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «C'è una richiesta nuova da valutare», a chi può approvarla.
 *
 * Parte solo quando la richiesta entra in coda (email confermata): una
 * richiesta che nessuno ha ancora confermato non disturba la redazione.
 */
class AdminVenueApplicationSubmitted extends Notification
{
    public function __construct(private readonly VenueApplication $application) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vars = [
            'venue' => $this->application->venue_name,
            'kind' => mb_strtolower($this->application->kind()->label()),
            'contact' => $this->application->contact_name,
        ];

        return (new MailMessage)
            ->subject(__('venue_applications.mail.admin_subject', $vars))
            ->greeting(__('notifications.common.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('venue_applications.mail.admin_line', $vars))
            ->action(
                __('venue_applications.mail.admin_action'),
                VenueApplicationResource::getUrl('edit', ['record' => $this->application], panel: 'admin'),
            );
    }
}
