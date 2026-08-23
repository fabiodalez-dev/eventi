<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\VenueRole;
use App\Models\Venue;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * «Ora puoi gestire gli eventi di questo locale» (§10.6).
 *
 * È l'unica porta d'ingresso a `/gestione`: il pannello non ha registrazione
 * libera — chi entra è stato invitato, dalla redazione come referente o da un
 * referente come collaboratore.
 *
 * A chi non aveva ancora un account il messaggio porta il collegamento per
 * scegliere la password; a chi ce l'aveva già porta direttamente il pannello.
 * Sono due frasi diverse perché sono due situazioni diverse, e mandare a un
 * collaboratore di vecchia data un invito a "creare la password" lo
 * spaventerebbe.
 */
class VenueAccessGranted extends Notification
{
    public function __construct(
        private readonly Venue $venue,
        private readonly VenueRole $role,
        private readonly bool $needsPassword,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $panel = Filament::getPanel('venue');
        $product = (string) config('app.name');

        $message = (new MailMessage)
            ->subject(__('manage.invitation.subject', ['venue' => $this->venue->name, 'product' => $product]))
            ->greeting(__('manage.invitation.greeting', ['name' => $notifiable->name]))
            ->line(__('manage.invitation.intro', [
                'venue' => $this->venue->name,
                'role' => mb_strtolower($this->role->label()),
                'product' => $product,
            ]));

        if ($this->needsPassword && $notifiable instanceof CanResetPassword) {
            return $message
                ->line(__('manage.invitation.password_hint'))
                ->action(
                    __('manage.invitation.password_action'),
                    $panel->getResetPasswordUrl(Password::broker()->createToken($notifiable), $notifiable),
                )
                ->line(__('manage.invitation.ignore'));
        }

        return $message->action(
            __('manage.invitation.open_action'),
            $panel->getUrl($this->venue) ?? url('/'),
        );
    }
}
