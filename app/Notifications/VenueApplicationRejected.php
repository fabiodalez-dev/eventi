<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\VenueApplication;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «Non ho potuto accogliere la tua richiesta», a chi l'ha scritta.
 *
 * Parte solo se chi valuta lo sceglie, e solo verso l'account che ha mandato
 * la richiesta: mai verso l'indirizzo scritto in un modulo pubblico, che
 * potrebbe essere di chiunque. Il motivo interno resta nella scheda; qui va
 * soltanto il messaggio scritto apposta per chi legge, se c'è.
 */
class VenueApplicationRejected extends Notification
{
    public function __construct(
        private readonly VenueApplication $application,
        private readonly ?string $message = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vars = ['venue' => $this->application->venue_name, 'product' => (string) config('app.name')];

        $mail = (new MailMessage)
            ->subject(__('venue_applications.mail.rejected_subject', $vars))
            ->greeting(__('notifications.common.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('venue_applications.mail.rejected_line', $vars));

        $message = trim((string) $this->message);
        if ($message !== '') {
            $mail->line(__('venue_applications.mail.rejected_reason'))->line($message);
        }

        return $mail
            ->line(__('venue_applications.mail.rejected_next'))
            ->action(__('venue_applications.mail.received_action'), route('account.applications'));
    }
}
