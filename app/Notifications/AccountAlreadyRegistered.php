<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «Hai già un account»: quello che arriva a chi si registra con un indirizzo
 * già registrato (§15.2).
 *
 * La risposta del modulo è la stessa di un indirizzo nuovo, così chi prova un
 * indirizzo non scopre se è iscritto: lo scopre solo chi legge la casella. Il
 * messaggio porta il collegamento di accesso ordinario — che prova la casella
 * e, se l'account è in sospeso, azzera le credenziali scelte da altri
 * (`ClaimMailbox`) — e ricorda «password dimenticata». L'account non cambia:
 * né la password né i dati scritti nel modulo.
 */
class AccountAlreadyRegistered extends Notification
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
            ->subject(__('account.mail.already_registered.subject', ['product' => config()->string('app.name')]))
            ->line(__('account.mail.already_registered.intro'))
            ->action(__('account.mail.already_registered.action'), $this->url)
            ->line(__('account.mail.magic.expires', ['minutes' => config()->integer('account.magic_link_minutes')]))
            ->line(__('account.mail.already_registered.password'))
            ->line(__('account.mail.already_registered.ignore'));
    }
}
