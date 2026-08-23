<?php

declare(strict_types=1);

namespace App\Filament\Venue\Support;

use App\Actions\PublishEventAction;
use App\Enums\EventStatus;
use App\Models\Event;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

/**
 * Che cosa succede quando un gestore preme «pubblica».
 *
 * Non sempre la stessa cosa, ed è voluto: `venues.auto_publish` decide se il
 * locale va online da solo o se passa dalla redazione. La domanda però non si
 * pone qui — la pone `EventPolicy::publish()`, che è già l'unico punto in cui
 * quella regola è scritta. Questa classe si limita a chiedere e a dare la
 * risposta giusta a chi sta guardando lo schermo: *pubblicato* oppure
 * *inviato alla redazione*.
 *
 * Un evento senza date non si pubblica: lo impone `PublishEventAction` e non
 * è una preferenza — un evento pubblicato senza occorrenze sarebbe online e
 * invisibile allo stesso tempo.
 */
final class EventPublication
{
    public static function submit(Event $event): EventStatus
    {
        if (Gate::allows('publish', $event) && app(PublishEventAction::class)->canPublish($event)) {
            app(PublishEventAction::class)->publish($event);

            return EventStatus::Published;
        }

        $event->status = EventStatus::Pending;
        $event->save();

        return EventStatus::Pending;
    }

    public static function notify(EventStatus $status): void
    {
        Notification::make()
            ->title($status === EventStatus::Published
                ? __('manage.notifications.published')
                : __('manage.notifications.sent_to_review'))
            ->body($status === EventStatus::Published
                ? __('manage.notifications.published_body')
                : __('manage.notifications.sent_to_review_body'))
            ->success()
            ->send();
    }
}
