<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ApplicationKind;
use App\Models\VenueApplication;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * «Ho ricevuto la tua richiesta», a chi l'ha scritta.
 *
 * Parte quando la richiesta entra in coda, cioè con l'email confermata: dice
 * cosa succede adesso e quanto ci vuole, così chi aspetta non manda una
 * seconda richiesta perché crede che la prima sia andata persa.
 */
class VenueApplicationReceived extends Notification
{
    public function __construct(private readonly VenueApplication $application) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vars = ['venue' => $this->application->venue_name, 'product' => (string) config('app.name')];
        $kind = $this->application->kind();

        return (new MailMessage)
            ->subject(__('venue_applications.mail.received_subject', $vars))
            ->greeting(__('notifications.common.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__(match ($kind) {
                ApplicationKind::Claim => 'venue_applications.mail.received_claim',
                ApplicationKind::Organizer => 'venue_applications.mail.received_organizer',
                ApplicationKind::Venue => 'venue_applications.mail.received_venue',
            }, $vars))
            ->line(__('venue_applications.mail.received_next'))
            ->action(
                __('venue_applications.mail.received_action'),
                Route::has('account.applications') ? route('account.applications') : route('account.profile'),
            )
            ->line(__('venue_applications.mail.received_ignore'));
    }
}
