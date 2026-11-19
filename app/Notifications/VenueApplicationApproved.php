<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ApplicationKind;
use App\Models\Venue;
use Filament\Facades\Filament;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «La tua richiesta è stata accolta», a chi l'ha mandata da collegato e con
 * l'email confermata: entra subito nel pannello del locale, senza
 * collegamenti a scadenza.
 *
 * Un locale nuovo nasce in bozza, e il messaggio lo dice: si completa la
 * scheda, poi la redazione la pubblica. Una rivendicazione consegna un locale
 * già online.
 */
class VenueApplicationApproved extends Notification
{
    public function __construct(
        private readonly Venue $venue,
        private readonly ApplicationKind $kind,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vars = ['venue' => $this->venue->name, 'product' => (string) config('app.name')];

        return (new MailMessage)
            ->subject(__('venue_applications.mail.approved_subject', $vars))
            ->greeting(__('notifications.common.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__($this->kind === ApplicationKind::Claim ? 'venue_applications.mail.approved_claim' : 'venue_applications.mail.approved_venue', $vars))
            ->action(
                __('venue_applications.mail.approved_action'),
                Filament::getPanel('venue')->getUrl($this->venue) ?? url('/gestione'),
            )
            ->line(__('venue_applications.mail.approved_help'));
    }
}
