<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Actions\DuplicateEventAction;
use App\Actions\ScheduleEventPublication;
use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\Events\EventDuplicateRequest;
use App\Http\Requests\Api\V1\Management\Events\EventScheduleRequest;
use App\Http\Requests\Api\V1\Management\Events\EventUpdateActionRequest;
use App\Services\Events\EventSubmission;
use App\Services\Management\ManagedEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Le azioni della scheda di un evento: pubblica (locale), invia alla
 * redazione (organizzazione), duplica, programma e annulla la
 * programmazione. Ognuna è l'azione già usata dal pannello.
 */
final class EventPublicationController extends Controller
{
    public function __construct(private readonly ManagedEvents $events) {}

    /**
     * «Pubblica»: online se il locale pubblica da sé, altrimenti alla
     * redazione. Visibile su bozza, in attesa e rifiutato; senza date no.
     */
    public function publish(EventUpdateActionRequest $request, EventSubmission $submission): JsonResponse
    {
        $event = $request->event();
        if (! $submission->canPublishFrom($event)) {
            throw ValidationException::withMessages(['event' => 'Questo evento non si può pubblicare nel suo stato attuale.']);
        }
        if (! $event->occurrences()->exists()) {
            throw ValidationException::withMessages(['event' => 'Aggiungi almeno una data prima di pubblicare.']);
        }
        $status = $submission->publish($event, $request->actor());
        $outcome = $status === EventStatus::Draft && $event->scheduled_publish_at !== null ? 'scheduled' : $status->value;

        return response()->json(['data' => $this->events->detail($event->refresh(), $request->subject()), 'meta' => ['outcome' => $outcome]]);
    }

    /** «Invia alla redazione» dell'organizzazione: bozza o rifiutato, con almeno una data. */
    public function submit(EventUpdateActionRequest $request, EventSubmission $submission): JsonResponse
    {
        $event = $request->event();
        if (! $submission->canSendToEditors($event)) {
            throw ValidationException::withMessages(['event' => 'Si invia alla redazione una bozza o un evento rifiutato.']);
        }
        $submission->sendToEditors($event, $request->actor());

        return response()->json(['data' => $this->events->detail($event->refresh(), $request->subject()), 'meta' => ['outcome' => 'pending']]);
    }

    /** «Duplica»: la copia in bozza, con tag e locandina, senza date. */
    public function duplicate(EventDuplicateRequest $request, DuplicateEventAction $duplicate): JsonResponse
    {
        $copy = $duplicate->execute($request->event(), $request->actor());

        return response()->json(['data' => $this->events->detail($copy->refresh(), $request->subject())], 201);
    }

    /** «Programma pubblicazione»: esce a quell'ora solo se autorizzato. */
    public function schedule(EventScheduleRequest $request, ScheduleEventPublication $schedule): JsonResponse
    {
        $event = $request->event();
        $schedule->schedule($event, $request->actor(), $request->publishAt());

        return response()->json(['data' => $this->events->detail($event->refresh(), $request->subject()), 'meta' => ['outcome' => $event->status->value]]);
    }

    /** «Annulla programmazione». */
    public function unschedule(EventUpdateActionRequest $request, ScheduleEventPublication $schedule): JsonResponse
    {
        $event = $request->event();
        $schedule->cancel($event, $request->actor());

        return response()->json(['data' => $this->events->detail($event->refresh(), $request->subject())]);
    }
}
