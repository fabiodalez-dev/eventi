<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «L'email del tuo account è cambiata», al **vecchio** indirizzo.
 *
 * Chi si prende un account cambia per prima cosa l'email, così le
 * reimpostazioni della password arrivano a lui: questo messaggio è l'unico
 * modo in cui il titolare lo viene a sapere quando ancora può reagire. Parte
 * verso un indirizzo che non è più dell'account (`Notification::route`), ed
 * è il motivo per cui non passa da `User::canReceiveNotifications()`.
 *
 * Il nuovo indirizzo si mostra mascherato: basta a riconoscerlo, non lo
 * consegna a chi legge una casella che magari non è più sua.
 */
class EmailAddressChanged extends Notification
{
    public function __construct(
        private readonly string $newEmail,
        private readonly bool $byStaff,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $product = config()->string('app.name');

        $message = (new MailMessage)
            ->subject(__('account.mail.email_changed.subject', ['product' => $product]))
            ->line(__($this->byStaff ? 'account.mail.email_changed.intro_staff' : 'account.mail.email_changed.intro', ['product' => $product, 'email' => self::mask($this->newEmail)]));
        // Solo chi cambia la propria email la riconferma; quella cambiata dallo staff la conferma lo staff nel pannello.
        if (! $this->byStaff) {
            $message->line(__('account.mail.email_changed.confirm'));
        }

        return $message->line(__('account.mail.email_changed.not_you', ['address' => config()->string('mail.from.address')]));
    }

    /** `mario.rossi@example.com` diventa `ma•••@example.com`. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'•••'.($domain !== '' ? '@'.$domain : '');
    }
}
