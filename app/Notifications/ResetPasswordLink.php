<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * «Reimposta la password» per chi accede dall'API (§13.4).
 *
 * Non si usa la notifica di Laravel per due ragioni: i suoi testi vivono
 * nelle traduzioni del framework e uscirebbero in inglese (§2 delle
 * convenzioni: nessuna stringa fuori da `lang/it`), e il suo collegamento
 * punta alla rotta `password.reset` del sito, che non esiste — chi reimposta
 * la password qui lo fa dall'applicazione, non da una pagina web.
 *
 * L'indirizzo di destinazione è configurabile (`API_PASSWORD_RESET_URL`)
 * perché è l'app a doverlo raccogliere: il giorno in cui esisterà anche una
 * pagina web, cambierà una riga di `.env` e non una di codice.
 */
class ResetPasswordLink extends Notification
{
    public function __construct(private readonly string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = is_string($notifiable->email ?? null) ? $notifiable->email : '';

        return (new MailMessage)
            ->subject(__('api.password.subject', ['product' => config()->string('app.name')]))
            ->line(__('api.password.intro'))
            ->action(__('api.password.action'), $this->url($email))
            ->line(__('api.password.expires', ['minutes' => config()->integer('auth.passwords.users.expire')]))
            ->line(__('api.password.ignore'));
    }

    /**
     * Il collegamento porta con sé il token e l'indirizzo: sono i due dati
     * che `POST /v1/auth/password/reset` richiede, e chiederli di nuovo a chi
     * ha già cliccato sarebbe chiedergli di ricopiare un token di 64 caratteri.
     */
    private function url(string $email): string
    {
        $template = config('api.password_reset_url');

        $template = is_string($template) && $template !== ''
            ? $template
            : rtrim(config()->string('app.url'), '/').'/reimposta-password?token={token}&email={email}';

        return str_replace(
            ['{token}', '{email}'],
            [$this->token, urlencode($email)],
            $template,
        );
    }
}
