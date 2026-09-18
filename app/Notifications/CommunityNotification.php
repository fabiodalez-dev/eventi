<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

final class CommunityNotification extends Notification
{
    /** @param  int|null  $actorId  chi ha causato l'avviso: serve a non ripetere lo stesso avviso a breve distanza */
    public function __construct(private readonly string $kind, private readonly string $url, private readonly ?int $actorId = null) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['title' => __('community.notifications.'.$this->kind), 'body' => __('community.notifications.open'), 'url' => $this->url, 'kind' => $this->kind, 'actor_id' => $this->actorId];
    }
}
