<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Organizer;
use Filament\Facades\Filament;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Le chiavi di un organizzatore, consegnate a chi ne aveva fatto richiesta.
 * Stessa forma dell'invito a un locale: chi non ha ancora una password la
 * sceglie dal collegamento, chi ce l'ha trova il pannello.
 */
class OrganizerAccessGranted extends Notification
{
    public function __construct(
        private readonly Organizer $organizer,
        private readonly bool $needsPassword,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $panel = Filament::getPanel('organizer');
        $product = (string) config('app.name');

        $message = (new MailMessage)
            ->subject(__('manage.organizer_invitation.subject', ['organizer' => $this->organizer->name, 'product' => $product]))
            ->greeting(__('manage.invitation.greeting', ['name' => $notifiable->name]))
            ->line(__('manage.organizer_invitation.intro', ['organizer' => $this->organizer->name, 'product' => $product]));

        $broker = Password::broker();

        if ($this->needsPassword && $notifiable instanceof CanResetPassword && $broker instanceof PasswordBroker) {
            return $message
                ->line(__('manage.invitation.password_hint'))
                ->action(__('manage.invitation.password_action'), $panel->getResetPasswordUrl($broker->createToken($notifiable), $notifiable))
                ->line(__('manage.invitation.ignore'));
        }

        return $message->action(__('manage.invitation.open_action'), $panel->getUrl($this->organizer) ?? url('/'));
    }
}
