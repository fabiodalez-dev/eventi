<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\DTOs\QuietHours;
use App\Enums\NotificationChannel;
use App\Models\User;
use App\Notifications\AppPush;
use App\Services\Notifications\ChannelSelector;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * La push degli avvisi che erano solo email (Q3), come canale di quella
 * stessa notifica.
 *
 * Non consegna: accoda `AppPush`, che parte dopo il commit e fuori dalla
 * richiesta. Le email di questi avvisi partono subito, dentro la richiesta
 * che approva o rifiuta; una chiamata a FCM nello stesso punto allungherebbe
 * quella richiesta e, se fallisse, la farebbe fallire con lei.
 *
 * Decide con le regole che valgono per tutti gli altri avvisi:
 * `ChannelSelector` (la consegna scelta nelle preferenze e i dispositivi
 * attivi negli ultimi giorni) e le ore di silenzio, che spostano la push
 * all'uscita. L'email non dipende da niente di questo, come prima.
 *
 * Un guasto qui si registra e non risale: l'email è già partita, e chi l'ha
 * mandata non deve crederla fallita (il rapporto mensile, per esempio, la
 * rimanderebbe).
 */
final class AppPushChannel
{
    public function __construct(private readonly ChannelSelector $selector) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'appPush')) {
            return;
        }

        try {
            if (! $notifiable->canReceiveNotifications() || ! in_array($this->selector->for($notifiable), [NotificationChannel::Push, NotificationChannel::Both], true)) {
                return;
            }
            /** @var array{type: string, title: string, body: string, url: string, event_id: int|null, management: array{subject_type: string, subject_id: int, area: string, id: int|null}|null} $payload */
            $payload = $notification->appPush($notifiable);
            $push = new AppPush($payload);

            $quiet = QuietHours::fromUser($notifiable);
            $now = CarbonImmutable::now($notifiable->timezone ?: (string) config('app.timezone'));
            if ($quiet !== null && $quiet->contains($now)) {
                $push->delay($quiet->endsAfter($now));
            }

            $notifiable->notify($push);
        } catch (Throwable $error) {
            report($error);
        }
    }
}
