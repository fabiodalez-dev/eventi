<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Actions\InviteVenueMemberAction;
use App\Enums\VenueRole;
use App\Models\Venue;
use Filament\Facades\Filament;
use Illuminate\Auth\Passwords\PasswordBroker;
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
 *
 * **Gli inviti da accettare (D64)** hanno una terza e una quarta frase: a chi
 * ha già un account arriva «Accetta l'invito» (`$acceptUrl`, firmato e a
 * scadenza), all'account nato dall'invito il collegamento per scegliere la
 * password, che vale come accettare (`$pending`). In nessuno dei due casi il
 * messaggio dice «da adesso puoi pubblicare»: non è ancora vero.
 */
class VenueAccessGranted extends Notification
{
    public function __construct(
        private readonly Venue $venue,
        private readonly VenueRole $role,
        private readonly bool $needsPassword,
        private readonly ?string $acceptUrl = null,
        private readonly bool $pending = false,
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

        $invitation = $this->acceptUrl !== null || $this->pending;

        $message = (new MailMessage)
            ->subject(__('manage.invitation.subject', ['venue' => $this->venue->name, 'product' => $product]))
            ->greeting(__('manage.invitation.greeting', ['name' => $notifiable->name]))
            ->line(__($invitation ? 'manage.invitation.invite_intro' : 'manage.invitation.intro', [
                'venue' => $this->venue->name,
                'role' => mb_strtolower($this->role->label()),
                'product' => $product,
            ]));

        if ($this->acceptUrl !== null) {
            return $message
                ->line(__('manage.invitation.accept_hint', ['days' => InviteVenueMemberAction::EXPIRES_DAYS]))
                ->action(__('manage.invitation.accept_action'), $this->acceptUrl)
                ->line(__('manage.invitation.decline_hint'));
        }

        /*
         * Il gettone per scegliere la password lo emette `createToken()`, che
         * NON sta nel contratto `PasswordBroker` — quello dichiara soltanto
         * `sendResetLink()` e `reset()` — ma sull'implementazione predefinita
         * di Laravel. Chiamarlo sul contratto funziona a runtime e resta una
         * presunzione taciuta: qui la si dichiara.
         *
         * Se un giorno il broker fosse sostituito con un'implementazione che
         * quel metodo non ha, l'invito parte lo stesso e porta al pannello
         * invece che al modulo della password — che è già il ramo previsto per
         * chi un account ce l'ha. Meglio un invito che manda nel posto quasi
         * giusto di un errore che non fa partire niente.
         */
        $broker = Password::broker();

        if ($this->needsPassword && $notifiable instanceof CanResetPassword && $broker instanceof PasswordBroker) {
            $token = $broker->createToken($notifiable);

            /*
             * L'invito da accettare porta alla pagina del sito, non a quella
             * del pannello: Filament rifiuta di reimpostare la password a chi
             * il pannello non lo può ancora aprire, e qui è proprio la
             * password a dire di sì all'invito.
             */
            return $message
                ->line(__($this->pending ? 'manage.invitation.password_accept_hint' : 'manage.invitation.password_hint'))
                ->action(
                    __('manage.invitation.password_action'),
                    $this->pending
                        ? route('account.password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()])
                        : $panel->getResetPasswordUrl($token, $notifiable),
                )
                ->line(__('manage.invitation.ignore'));
        }

        return $message->action(
            __('manage.invitation.open_action'),
            $panel->getUrl($this->venue) ?? url('/'),
        );
    }
}
