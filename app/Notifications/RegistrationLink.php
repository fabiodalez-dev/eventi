<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «Conferma l'indirizzo ed entra»: il messaggio della registrazione (§15.2).
 *
 * La registrazione non dice più a chi la fa se l'indirizzo era libero: la
 * risposta è sempre «ti abbiamo scritto», e la differenza sta solo in quello
 * che arriva nella casella. Per un indirizzo nuovo arriva questo, con il
 * collegamento di accesso che conferma l'email ed entra. Il collegamento è lo
 * stesso del collegamento di accesso — firmato, a scadenza, legato al browser
 * o all'installazione che si è registrata — e lì la password appena scelta
 * resta valida (`ClaimMailbox::confermaRegistrazione()`).
 *
 * Per un indirizzo già registrato parte `AccountAlreadyRegistered`.
 */
class RegistrationLink extends Notification
{
    public function __construct(public readonly string $url) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('account.mail.registration.subject', ['product' => config()->string('app.name')]))
            ->line(__('account.mail.registration.intro'))
            ->action(__('account.mail.registration.action'), $this->url)
            ->line(__('account.mail.magic.expires', ['minutes' => config()->integer('account.magic_link_minutes')]))
            ->line(__('account.mail.registration.ignore'));
    }
}
