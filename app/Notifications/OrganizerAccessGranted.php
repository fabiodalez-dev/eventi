<?php

declare(strict_types=1);

namespace App\Notifications;

use App\DTOs\NotificationMessage;
use App\Enums\NotificationType;
use App\Models\Organizer;
use App\Models\User;
use App\Notifications\Concerns\AlsoInTheApp;
use App\Services\Management\ManagementNoticeTarget;
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
 *
 * Nell'app (Q3) arriva anche in casella e in push, e porta alla pagina
 * dell'organizzazione nella gestione. Il collegamento per la password resta
 * solo nell'email: nell'archivio c'è l'indirizzo del pannello.
 */
class OrganizerAccessGranted extends Notification
{
    use AlsoInTheApp;

    public function __construct(
        private readonly Organizer $organizer,
        private readonly bool $needsPassword,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channelsWithApp($notifiable);
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
                ->action(__('manage.invitation.password_action'), route('account.password.reset', [
                    'token' => $broker->createToken($notifiable),
                    'email' => $notifiable->getEmailForPasswordReset(),
                ]))
                ->line(__('manage.invitation.ignore'));
        }

        return $message->action(__('manage.invitation.open_action'), $panel->getUrl($this->organizer) ?? url('/'));
    }

    protected function appMessage(User $user): NotificationMessage
    {
        $vars = ['organizer' => $this->organizer->name, 'product' => (string) config('app.name')];

        return new NotificationMessage(
            type: NotificationType::OrganizerAccessGranted,
            subject: __('manage.organizer_invitation.subject', $vars),
            heading: __('manage.organizer_invitation.subject', $vars),
            lines: [__('manage.organizer_invitation.intro', $vars)],
            actionLabel: __('manage.invitation.open_action'),
            url: Filament::getPanel('organizer')->getUrl($this->organizer) ?? url('/'),
        );
    }

    protected function appTarget(User $user): ?array
    {
        return app(ManagementNoticeTarget::class)->subject($user, $this->organizer, ManagementNoticeTarget::AREA_HOME);
    }
}
