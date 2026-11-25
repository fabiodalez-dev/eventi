<?php

declare(strict_types=1);

namespace App\Filament\Venue\Support;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Services\Events\EventSubmission;
use Filament\Notifications\Notification;

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
    /** La regola sta in `EventSubmission`, la stessa che usa l'API di gestione. */
    public static function submit(Event $event): EventStatus
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return app(EventSubmission::class)->publish($event, $user);
    }

    public static function notify(EventStatus $status): void
    {
        if ($status === EventStatus::Draft) {
            Notification::make()->success()->title('Pubblicazione programmata')->send();

            return;
        }
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
