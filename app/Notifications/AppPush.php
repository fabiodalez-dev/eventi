<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use App\Services\Notifications\ChannelSelector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * La push di un avviso che era solo email (Q3), accodata da `AppPushChannel`.
 *
 * Porta un contenuto già composto (titolo, testo, indirizzo, bersaglio) e non
 * il modello: arriva al telefono ciò che è stato scritto nell'archivio nello
 * stesso momento, anche se nel frattempo l'ordine o la richiesta cambiano.
 * I campi sono quelli di `ScheduledMessage::toFcm`, così l'app li legge con
 * lo stesso codice: `management_*` dice quale schermata aprire. L'app non si
 * fida comunque del bersaglio: la schermata chiede i dati al server, che
 * rifà il controllo d'accesso.
 *
 * Parte dopo il commit: se la transazione che ha deciso si ribalta, con lei
 * spariscono la riga dell'archivio e questa push.
 */
final class AppPush extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{type: string, title: string, body: string, url: string, event_id: int|null, management: array{subject_type: string, subject_id: int, area: string, id: int|null}|null}  $payload
     */
    public function __construct(public readonly array $payload)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $selector = app(ChannelSelector::class);

        return array_values(array_filter([
            $selector->webConfigured() ? WebPushChannel::class : null,
            $selector->fcmConfigured() ? FcmChannel::class : null,
        ]));
    }

    /**
     * Al momento della consegna si guarda di nuovo la persona: un account
     * chiuso, una casella diventata non recapitabile o un interruttore spento
     * nel frattempo (il rapporto mensile ne ha uno) fermano la push.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $user = $notifiable instanceof User ? User::query()->find($notifiable->getKey()) : null;
        $type = NotificationType::tryFrom($this->payload['type']);

        return $user instanceof User && $user->canReceiveNotifications() && $type !== null && $type->isEnabledFor($user);
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->payload['title'])
            ->body($this->payload['body'])
            ->tag($this->payload['type'])
            ->data(['type' => $this->payload['type'], 'url' => $this->payload['url']]);
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        $management = $this->payload['management'];

        return FcmMessage::create()
            ->data([
                'title' => $this->payload['title'],
                'body' => mb_substr($this->payload['body'], 0, 1000),
                'user_id' => $notifiable instanceof User ? (string) $notifiable->getKey() : '',
                'type' => $this->payload['type'],
                'url' => $this->payload['url'],
                'occurrence_id' => '',
                'event_id' => $this->payload['event_id'] === null ? '' : (string) $this->payload['event_id'],
                ...($management === null ? [] : [
                    'management_subject_type' => $management['subject_type'],
                    'management_subject_id' => (string) $management['subject_id'],
                    'management_area' => $management['area'],
                    'management_id' => $management['id'] === null ? '' : (string) $management['id'],
                ]),
            ])
            ->android([
                'priority' => 'high',
                'ttl' => '3600s',
            ]);
    }
}
