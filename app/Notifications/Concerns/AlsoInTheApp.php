<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\DTOs\NotificationMessage;
use App\Models\User;
use App\Notifications\Channels\AppPushChannel;

/**
 * Un avviso che era solo email e che adesso arriva anche nell'app (Q3).
 *
 * L'email non cambia: `toMail` resta quello di prima, e chi la manda la
 * manda come prima (stessi destinatari, stesso momento). Accanto, solo per
 * chi ha un account, partono altri due canali:
 *
 * - l'archivio (`database`), con la stessa forma degli avvisi di
 *   `ScheduledMessage` — `type`, `title`, `body`, `url`, … più `management`,
 *   la schermata da aprire nell'app — così la casella dell'account, quella
 *   della gestione e la campanella del sito lo leggono senza saperne niente;
 * - la push (`AppPushChannel`), che si accoda a parte e decide lei se e
 *   dove consegnare.
 *
 * Il contenuto si scrive apposta in `appMessage()` e non si ricava da
 * `toMail()`: comporre l'email emette, per gli inviti, il gettone della
 * password, e chiamarla una seconda volta invaliderebbe il collegamento
 * appena spedito.
 *
 * Un destinatario senza account (`Notification::route('mail', …)`, il
 * committente scritto su una campagna) riceve soltanto l'email, come prima.
 */
trait AlsoInTheApp
{
    /** Il testo dell'avviso nella casella e sul telefono, già tradotto. */
    abstract protected function appMessage(User $user): NotificationMessage;

    /**
     * Dove porta l'avviso dentro l'app (`ManagementNoticeTarget`), o `null`
     * se la persona non ha (più) accesso a niente di pertinente.
     *
     * @return array{subject_type: string, subject_id: int, area: string, id: int|null}|null
     */
    abstract protected function appTarget(User $user): ?array;

    /**
     * L'email prima di tutto: se la posta fallisce, chi manda (per esempio
     * il rapporto mensile) riprova più tardi, e non deve trovare una riga in
     * archivio già scritta.
     *
     * @return list<string>
     */
    protected function channelsWithApp(object $notifiable): array
    {
        return $notifiable instanceof User ? ['mail', 'database', AppPushChannel::class] : ['mail'];
    }

    /** Il tipo di dominio, come per `ScheduledMessage`: è ciò su cui l'app e l'area «Notifiche» filtrano. */
    public function databaseType(object $notifiable): string
    {
        return $notifiable instanceof User ? $this->appMessage($notifiable)->type->value : static::class;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }
        $target = $this->appTarget($notifiable);

        return [...$this->appMessage($notifiable)->toArray(), ...($target === null ? [] : ['management' => $target])];
    }

    /**
     * Ciò che la push porta: gli stessi campi piatti di `ScheduledMessage::toFcm`.
     *
     * @return array{type: string, title: string, body: string, url: string, event_id: int|null, management: array{subject_type: string, subject_id: int, area: string, id: int|null}|null}
     */
    public function appPush(User $user): array
    {
        $message = $this->appMessage($user);

        return [
            'type' => $message->type->value,
            'title' => $message->heading,
            'body' => implode(' ', $message->lines),
            'url' => $message->url,
            'event_id' => $message->eventId,
            'management' => $this->appTarget($user),
        ];
    }
}
