<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Il collegamento di accesso senza password (§15.2), che il piano raccomanda
 * come via primaria: per un uso saltuario come questo, ricordarsi una password
 * è attrito puro, e l'attrito si paga con gli account che non nascono.
 *
 * Il collegamento è firmato, dura quindici minuti e porta con sé la data
 * dell'ultimo cambio di password: cambiarla invalida i collegamenti già
 * spediti, che è ciò che si vuole quando si cambia password perché si teme
 * un accesso altrui.
 */
class MagicLoginLink extends Notification
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
            ->subject(__('account.mail.magic.subject', ['product' => config()->string('app.name')]))
            ->line(__('account.mail.magic.intro'))
            ->action(__('account.mail.magic.action'), self::url($notifiable))
            ->line(__('account.mail.magic.expires', ['minutes' => config()->integer('account.magic_link_minutes')]))
            ->line(__('account.mail.magic.ignore'));
    }

    public static function url(object $notifiable): string
    {
        $user = $notifiable instanceof User ? $notifiable : null;

        return URL::temporarySignedRoute(
            'account.magic-link.login',
            now()->addMinutes(config()->integer('account.magic_link_minutes')),
            [
                'user' => $user?->getKey() ?? 0,
                'fingerprint' => self::fingerprint($user),
            ],
        );
    }

    /**
     * Un'impronta della password in vigore quando il collegamento è stato
     * spedito. Non è la password né qualcosa da cui si possa risalire ad essa:
     * serve solo a far scadere i collegamenti vecchi quando la password cambia.
     */
    public static function fingerprint(?User $user): string
    {
        return substr(hash_hmac('sha256', (string) $user?->password, (string) config('app.key')), 0, 32);
    }
}
