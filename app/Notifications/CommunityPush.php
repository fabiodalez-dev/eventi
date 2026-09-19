<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\WebPush\WebPushMessage;

final class CommunityPush extends Notification
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload, public string $noticeId, private string $channel) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [$this->channel];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)->title($this->payload['title'])->body(__('carpool.notifications.open'))->tag('community-'.$this->noticeId)
            ->data(['url' => $this->payload['url'], 'notification_id' => $this->noticeId, 'type' => $this->payload['category']]);
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create()->data(['title' => $this->payload['title'], 'body' => __('carpool.notifications.open'),
            'url' => $this->payload['url'], 'notification_id' => $this->noticeId, 'type' => $this->payload['category'], 'user_id' => (string) $notifiable->id])
            ->android(['priority' => 'high', 'ttl' => '3600s', 'collapse_key' => 'community']);
    }
}
