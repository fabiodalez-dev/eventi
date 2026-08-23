<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * «Conferma il tuo indirizzo» (§15.2).
 *
 * La verifica è la condizione perché una notifica possa partire — un account
 * non verificato può salvare, non può ricevere (`User::canReceiveNotifications()`).
 * Questo messaggio è quindi l'unico che viaggia verso un indirizzo non ancora
 * verificato, ed è la ragione per cui quel controllo non sta in
 * `routeNotificationForMail()`.
 *
 * Il collegamento è firmato e a scadenza: chiunque lo intercetti dopo l'ora
 * non ha in mano niente.
 */
class VerifyEmailLink extends Notification
{
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
            ->subject(__('account.mail.verify.subject', ['product' => config()->string('app.name')]))
            ->line(__('account.mail.verify.intro'))
            ->action(__('account.mail.verify.action'), self::url($notifiable))
            ->line(__('account.mail.verify.expires', ['minutes' => config()->integer('account.verification_link_minutes')]))
            ->line(__('account.mail.verify.ignore'));
    }

    /**
     * L'indirizzo firmato che `GET /email/verifica/{id}/{hash}` accetta.
     *
     * È `public` perché lo stesso indirizzo serve a `POST /v1/auth/verify-email`:
     * l'app raccoglie il collegamento ricevuto per posta e lo rimanda al
     * server, che ne verifica la firma. Un solo generatore per i due canali,
     * altrimenti la firma calcolata dall'uno non varrebbe per l'altro.
     */
    public static function url(object $notifiable): string
    {
        $id = $notifiable instanceof User ? (int) $notifiable->getKey() : 0;
        $email = is_string($notifiable->email ?? null) ? $notifiable->email : '';

        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(config()->integer('account.verification_link_minutes')),
            ['id' => $id, 'hash' => sha1($email)],
        );
    }
}
