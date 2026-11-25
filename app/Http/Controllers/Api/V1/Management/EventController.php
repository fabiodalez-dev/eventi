<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\EventStatus;
use App\Enums\RecurrenceFrequency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\Events\EventDeleteRequest;
use App\Http\Requests\Api\V1\Management\Events\EventIndexRequest;
use App\Http\Requests\Api\V1\Management\Events\EventUpdateRequest;
use App\Http\Requests\Api\V1\Management\Events\EventViewRequest;
use App\Http\Requests\Api\V1\Management\Events\OrganizerEventStoreRequest;
use App\Http\Requests\Api\V1\Management\Events\VenueEventStoreRequest;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Venue;
use App\Services\Events\EventSubmission;
use App\Services\Events\OrganizerEventEditor;
use App\Services\Events\RecurrenceSetup;
use App\Services\Events\VenueEventEditor;
use App\Services\Import\FacebookImportDrafts;
use App\Services\Management\ManagedEvents;
use App\Support\Management\ManagementSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gli eventi del locale o dell'organizzazione nell'app: elenco, scheda,
 * creazione, modifica, eliminazione.
 *
 * Il salvataggio non sta qui: lo fanno `VenueEventEditor` e
 * `OrganizerEventEditor`, gli stessi delle pagine dei pannelli, così bozza,
 * prima data, valori imparati e ritorno in revisione hanno una regola sola.
 */
final class EventController extends Controller
{
    public function __construct(private readonly ManagedEvents $events) {}

    /** L'elenco a card, con le schede Tutti, Prossimi, Passati e Bozze. */
    public function index(EventIndexRequest $request): JsonResponse
    {
        $page = $this->events->page(
            ManagementSubject::require(),
            $request->string('tab')->toString() ?: 'all',
            ManagedEvents::statusFilter($request->input('status')),
            $request->string('q')->toString(),
            $request->integer('page', 1),
        );

        return response()->json([
            'data' => collect($page->items())->map(fn (Event $event): array => $this->events->card($event))->values()->all(),
            'meta' => [
                'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
                'total' => $page->total(),
            ],
        ]);
    }

    /** La scheda in sola lettura con i campi del modulo e le azioni possibili. */
    public function show(EventViewRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->events->detail($request->event(), $request->subject())]);
    }

    /**
     * Il nuovo evento del locale: «Salva come bozza» o «Pubblica», come il
     * modulo rapido di `/gestione`. La pubblicazione scrive la prima data, la
     * ripetizione e i valori imparati, poi mette online o manda alla
     * redazione secondo `venues.auto_publish`.
     */
    public function storeVenue(VenueEventStoreRequest $request, VenueEventEditor $editor, EventSubmission $submission, FacebookImportDrafts $imports): JsonResponse
    {
        $venue = $request->venue();
        $user = $request->actor();
        $subject = ManagementSubject::require();
        $data = $request->validated();
        $facebook = $imports->get($user, $subject->type().':'.$venue->getKey(), $request->input('facebook_import'));

        // La bozza può nascere prima della categoria: prende quella abituale del locale.
        if (blank($data['category_id'] ?? null)) {
            $data['category_id'] = $editor->fallbackCategoryId($venue);
            if ($data['category_id'] === null) {
                throw ValidationException::withMessages(['category_id' => __('validation.required', ['attribute' => 'categoria'])]);
            }
        }

        $columns = Arr::except($data, ['publish', 'draft_id', 'facebook_import', 'tags', 'starts_at', 'ends_at', 'repeat']);
        [$event, $status] = DB::transaction(function () use ($editor, $submission, $request, $venue, $user, $data, $columns, $facebook): array {
            $event = $editor->persist($venue, $user, $request->draft(), $columns, $facebook, '');
            if (array_key_exists('tags', $data)) {
                $event->tags()->sync(array_map('intval', $data['tags'] ?? []));
            }
            if (! $request->publishes()) {
                return [$event, EventStatus::Draft];
            }

            $occurrence = $editor->createFirstDate($event, $data['starts_at'] ?? null, $data['ends_at'] ?? null);
            if (is_array($data['repeat'] ?? null)) {
                app(RecurrenceSetup::class)->apply(
                    $event,
                    RecurrenceFrequency::tryFrom((string) ($data['repeat']['frequency'] ?? '')),
                    $data['repeat']['weekdays'] ?? [],
                    $data['repeat']['until'] ?? null,
                );
            }
            $editor->rememberDefaults($venue, $event, $occurrence);

            return [$event, $submission->publish($event, $user)];
        });

        $photo = $facebook !== [] ? $imports->attachPhoto($event, $facebook) : false;
        if ($facebook !== []) {
            $imports->forget($request->input('facebook_import'));
        }

        return $this->saved($event->refresh(), $subject, $request->draft() === null ? 201 : 200, [
            'outcome' => $this->outcome($event, $status),
            'facebook_photo' => $facebook === [] ? null : $photo,
        ]);
    }

    /**
     * Il nuovo evento dell'organizzazione: sempre in bozza, a nome
     * dell'organizzazione, con la prima data facoltativa.
     */
    public function storeOrganizer(OrganizerEventStoreRequest $request, OrganizerEventEditor $editor, FacebookImportDrafts $imports): JsonResponse
    {
        $organizer = $request->organizer();
        $user = $request->actor();
        $subject = ManagementSubject::require();
        $facebook = $imports->get($user, $subject->type().':'.$organizer->getKey(), $request->input('facebook_import'));

        $event = $editor->create($organizer, $user, $request->validated(), $facebook);
        $photo = $facebook !== [] ? $imports->attachPhoto($event, $facebook) : false;
        if ($facebook !== []) {
            $imports->forget($request->input('facebook_import'));
        }

        return $this->saved($event->refresh(), $subject, 201, [
            'outcome' => 'draft',
            'facebook_photo' => $facebook === [] ? null : $photo,
        ]);
    }

    /**
     * La modifica. Su un evento pubblicato può rimandare in revisione:
     * `meta.sent_to_review` lo dice all'app, che lo scrive come il pannello.
     */
    public function update(EventUpdateRequest $request, VenueEventEditor $venueEditor, OrganizerEventEditor $organizerEditor): JsonResponse
    {
        $event = $request->event();
        $subject = $request->subject();
        $model = $subject->model;
        $data = $request->validated();

        $sent = $model instanceof Venue
            ? $venueEditor->update($event, $model, $request->actor(), $data)
            : $organizerEditor->update($event, $this->organizer($model), $request->actor(), $data);

        return $this->saved($event->refresh(), $subject, 200, ['sent_to_review' => $sent]);
    }

    /** «Elimina» (solo il locale, come il menu della modifica del pannello). */
    public function destroy(EventDeleteRequest $request): Response
    {
        $request->event()->delete();

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function saved(Event $event, ManagementSubject $subject, int $status, array $meta): JsonResponse
    {
        return response()->json(['data' => $this->events->detail($event, $subject), 'meta' => $meta], $status);
    }

    /** `draft`, `published`, `pending` o `scheduled` (in bozza con un orario di uscita). */
    private function outcome(Event $event, EventStatus $status): string
    {
        if ($status === EventStatus::Draft && $event->scheduled_publish_at !== null) {
            return 'scheduled';
        }

        return $status->value;
    }

    private function organizer(Venue|Organizer $model): Organizer
    {
        abort_unless($model instanceof Organizer, 404);

        return $model;
    }
}
