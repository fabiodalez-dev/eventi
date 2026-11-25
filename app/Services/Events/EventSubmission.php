<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Actions\PublishEventAction;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Che cosa succede quando chi gestisce preme «Pubblica» (locale) o «Invia
 * alla redazione» (organizzazione).
 *
 * Stava in `EventPublication::submit` e nella pagina «Modifica evento» del
 * pannello dell'organizzazione; è qui perché lo chiamano anche le rotte
 * dell'app. La domanda «può andare online da solo?» resta di
 * `EventPolicy::publish`, che guarda `venues.auto_publish`.
 */
final class EventSubmission
{
    /**
     * Il «Pubblica» del locale: online se il locale pubblica da sé e l'evento
     * ha almeno una data, altrimenti in attesa della redazione. Restituisce
     * lo stato risultante (`Draft` se c'era una pubblicazione programmata nel
     * futuro: esce a quell'ora).
     */
    public function publish(Event $event, User $user): EventStatus
    {
        Gate::forUser($user)->authorize('update', $event);
        abort_if(in_array($event->status, [EventStatus::Cancelled, EventStatus::Archived], true), 403);
        $publisher = app(PublishEventAction::class);
        if (Gate::forUser($user)->allows('publish', $event) && $publisher->canPublish($event)) {
            $publisher->publish($event);

            return $event->status;
        }

        $event->status = EventStatus::Pending;
        $event->save();

        return EventStatus::Pending;
    }

    /**
     * «Invia alla redazione» dell'organizzazione: solo da bozza o rifiutato,
     * e solo con almeno una data.
     *
     * @throws ValidationException se l'evento non ha date
     */
    public function sendToEditors(Event $event, User $user): void
    {
        Gate::forUser($user)->authorize('update', $event);
        if (! $event->occurrences()->exists()) {
            throw ValidationException::withMessages(['event' => 'Aggiungi almeno una data prima di inviare']);
        }
        $event->update(['status' => EventStatus::Pending]);
    }

    /** Lo stato da cui l'organizzazione può inviare alla redazione. */
    public function canSendToEditors(Event $event): bool
    {
        return in_array($event->status, [EventStatus::Draft, EventStatus::Rejected], true);
    }

    /** Lo stato da cui il locale vede «Pubblica» (bozza, in attesa, rifiutato). */
    public function canPublishFrom(Event $event): bool
    {
        return in_array($event->status, [EventStatus::Draft, EventStatus::Pending, EventStatus::Rejected], true);
    }
}
