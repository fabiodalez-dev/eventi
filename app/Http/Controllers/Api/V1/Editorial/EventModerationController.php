<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Actions\DuplicateEventAction;
use App\Actions\MergeEventsAction;
use App\Actions\ModerateEventAction;
use App\Actions\PublishEventAction;
use App\Enums\ApiErrorCode;
use App\Enums\EventStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Editorial\EditorialEvents;
use App\Support\Editorial\EditorialDesk;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Le decisioni della redazione su un evento (R-EV-08…14, 17), le stesse di
 * `EventModeration`, `EventResource::mergeAction` e `DeleteAction` del
 * pannello: ogni azione chiede alla `EventPolicy` e fa il lavoro con le
 * classi di `app/Actions`. Rispondono con la scheda aggiornata e il
 * messaggio del pannello (`message`).
 *
 * Pericolose (password recente, decisione 4): annulla, unisci, elimina.
 * L'eliminazione definitiva resta solo sul sito (e il pannello oggi non la
 * offre agli eventi).
 */
final class EventModerationController extends Controller
{
    /** Il tetto del motivo di un rifiuto: la colonna è un `text`, il pannello non ne mette altri. */
    public const REASON_MAX = 16000;

    public function __construct(private readonly EditorialEvents $events) {}

    /**
     * «Pubblica», o «Approva programmazione» se l'evento ha una
     * pubblicazione programmata nel futuro. Un evento senza date non si
     * pubblica: 422 su `event`.
     */
    public function publish(int $event, PublishEventAction $action): JsonResponse
    {
        $record = EventController::findActive($event);
        Gate::authorize('publish', $record);
        $this->expect($record->status !== EventStatus::Published);
        if (! $action->canPublish($record)) {
            throw ValidationException::withMessages(['event' => __('admin.notifications.cannot_publish_without_date')]);
        }
        $action->publish($record);

        return $this->respond($record, $record->scheduled_publish_at !== null ? __('editorial-events.scheduled_approved') : __('admin.notifications.published'));
    }

    /** «Togli dalla pubblicazione»: torna in bozza e perde la programmazione. */
    public function unpublish(int $event, PublishEventAction $action): JsonResponse
    {
        $record = EventController::findActive($event);
        Gate::authorize('publish', $record);
        $this->expect($record->status === EventStatus::Published);
        $action->unpublish($record);

        return $this->respond($record, __('admin.notifications.unpublished'));
    }

    /** «Rifiuta» con il motivo (`reason`, obbligatorio): lo legge chi ha proposto l'evento. */
    public function reject(Request $request, int $event, ModerateEventAction $action): JsonResponse
    {
        $record = EventController::findActive($event);
        Gate::authorize('moderate', $record);
        $this->expect($record->status !== EventStatus::Rejected);
        $data = $request->validate(['reason' => ['required', 'string', 'max:'.self::REASON_MAX]]);
        $action->reject($record, (string) $data['reason']);

        return $this->respond($record, __('admin.notifications.rejected'));
    }

    /**
     * «Annulla l'evento» con una nota facoltativa (`note`) per le date: le
     * date future non ancora annullate si annullano con lui e chi le ha
     * salvate riceve l'avviso. Pericolosa: chiede la password recente.
     */
    public function cancel(Request $request, int $event, ModerateEventAction $action): JsonResponse
    {
        $record = EventController::findActive($event);
        Gate::authorize('moderate', $record);
        $this->expect($record->status !== EventStatus::Cancelled);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $action->cancel($record, filled($data['note'] ?? null) ? (string) $data['note'] : null);

        return $this->respond($record, __('admin.notifications.cancelled'));
    }

    /**
     * «Metti in evidenza» (`featured: true`, con `until` facoltativo, una
     * data ISO 8601) o «Togli dall'evidenza» (`featured: false`).
     */
    public function feature(Request $request, int $event, ModerateEventAction $action): JsonResponse
    {
        $record = EventController::findActive($event);
        Gate::authorize('feature', $record);
        $data = $request->validate([
            'featured' => ['required', 'boolean'],
            'until' => ['nullable', 'date'],
        ]);
        $featured = (bool) $data['featured'];
        $until = $featured && filled($data['until'] ?? null) ? Carbon::parse((string) $data['until'])->utc() : null;
        $action->setFeatured($record, $featured, $until);

        return $this->respond($record, $featured ? __('admin.notifications.featured') : __('admin.notifications.unfeatured'));
    }

    /** «Duplica»: la copia nasce bozza, senza date; risponde con la scheda della copia (201). */
    public function duplicate(int $event, DuplicateEventAction $action): JsonResponse
    {
        $record = EventController::findActive($event);
        Gate::authorize('create', [Event::class, $record->venue]);
        $copy = $action->execute($record, EditorialDesk::require()->user);

        return $this->respond($copy, __('admin.notifications.duplicated'), 201);
    }

    /**
     * I doppioni possibili per «Unisci»: eventi della stessa città, non
     * cestinati, diversi da questo, per titolo (`q`), venti al massimo, come
     * la ricerca del modulo del pannello.
     */
    public function mergeCandidates(Request $request, int $event): JsonResponse
    {
        $record = EventController::findActive($event);
        Gate::authorize('delete', $record);
        $search = trim((string) $request->query('q', ''));
        $like = '%'.addcslashes($search, '%_\\').'%';
        $candidates = Event::query()->where('city_id', $record->city_id)->whereKeyNot($record->getKey())
            ->where('title', 'like', $like)->orderBy('title')->limit(20)->get(['id', 'title', 'slug']);

        return response()->json(['data' => $candidates->map(fn (Event $candidate): array => [
            'id' => (int) $candidate->getKey(),
            'title' => $candidate->title,
            'slug' => $candidate->slug,
            'label' => $candidate->title.' ('.$candidate->slug.')',
        ])->values()->all()]);
    }

    /**
     * «Unisci con un doppione»: questo evento resta, `absorbed` (della
     * stessa città) viene assorbito e cestinato, il suo indirizzo diventa un
     * redirect. Chiede `delete` su tutti e due (403 se manca sull'assorbito).
     * Pericolosa: chiede la password recente.
     */
    public function merge(Request $request, int $event, MergeEventsAction $action): JsonResponse
    {
        $record = EventController::findActive($event);
        Gate::authorize('delete', $record);
        $data = $request->validate(['absorbed' => ['required', 'integer']]);
        $absorbed = Event::query()->where('city_id', $record->city_id)->whereKeyNot($record->getKey())->find((int) $data['absorbed']);
        if (! $absorbed instanceof Event) {
            throw ValidationException::withMessages(['absorbed' => __('validation.exists', ['attribute' => __('admin.events.merge_target')])]);
        }
        // L'assorbito finisce nel cestino: serve il permesso di eliminarlo, non solo quello sull'evento che resta.
        Gate::authorize('delete', $absorbed);
        $report = $action->execute($record, $absorbed, EditorialDesk::require()->user);

        return response()->json([
            'data' => $this->events->detail($record->refresh(), EditorialDesk::require()->user),
            'message' => __('admin.events.merged', ['title' => $absorbed->title]),
            'detail' => __('admin.events.merged_body', $report),
            'report' => $report,
        ]);
    }

    /** «Elimina»: nel cestino, da dove si ripristina. Pericolosa: chiede la password recente. */
    public function destroy(int $event): JsonResponse
    {
        $record = EventController::findActive($event);
        Gate::authorize('delete', $record);
        $record->delete();

        return response()->json(['data' => ['id' => (int) $record->getKey(), 'trashed' => true], 'message' => __('editorial-events.deleted')]);
    }

    /**
     * «Elimina» su più righe (`ids`, fino a 50): come l'azione di gruppo del
     * pannello, ognuna chiede il suo permesso e quelle negate restano.
     * Pericolosa: chiede la password recente.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:50'], 'ids.*' => ['integer']]);
        $gate = Gate::forUser(EditorialDesk::require()->user);
        $deleted = [];
        foreach (Event::query()->with(['venue', 'organizer'])->whereKey(array_map('intval', $data['ids']))->get() as $record) {
            if ($gate->allows('delete', $record)) {
                $record->delete();
                $deleted[] = (int) $record->getKey();
            }
        }

        return response()->json(['data' => ['deleted' => $deleted, 'skipped' => count($data['ids']) - count($deleted)]]);
    }

    /** «Ripristina» un evento dal cestino. */
    public function restore(int $event): JsonResponse
    {
        $record = EventController::find($event);
        Gate::authorize('restore', $record);
        $this->expect($record->trashed());
        $record->restore();

        return $this->respond($record, __('editorial-events.restored'));
    }

    /** Un'azione che con lo stato di adesso il pannello non mostra (pubblicare un pubblicato): 409. */
    private function expect(bool $condition): void
    {
        if (! $condition) {
            throw new ApiException(ApiErrorCode::Conflict, __('editorial-events.action_not_available'));
        }
    }

    private function respond(Event $event, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->events->detail($event->refresh(), EditorialDesk::require()->user), 'message' => $message], $status);
    }
}
