<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\Contact\ContactEmailConfirmation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * La richiesta di conferma del recapito del modulo contatti. Parte verso un
 * indirizzo che nessuno ha ancora dimostrato, quindi dice chi lo ha indicato
 * e che ignorarla basta: senza clic non arriva nessun messaggio.
 *
 * Il nome della scheda lo scrive la redazione (nome del locale o
 * dell'organizzatore), non chi indica il recapito.
 */
class ContactEmailConfirmationRequested extends Notification
{
    public function __construct(public string $targetName, public string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('contact.confirmation.mail_subject', ['name' => $this->targetName]))
            ->line(__('contact.confirmation.mail_intro', ['name' => $this->targetName, 'product' => config()->string('app.name')]))
            ->action(__('contact.confirmation.mail_action'), $this->url)
            ->line(__('contact.confirmation.mail_expires', ['days' => ContactEmailConfirmation::LINK_DAYS]))
            ->line(__('contact.confirmation.mail_ignore'));
    }
}
