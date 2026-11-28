<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Actions\CreateSharedEventTag;
use App\Actions\ScheduleEventPublication;
use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireRecentPassword;
use App\Http\Requests\Api\V1\Management\ImageRules;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Tag;
use App\Models\Venue;
use App\Services\Editorial\EditorialEventForm;
use App\Services\Editorial\EditorialEventMedia;
use App\Services\Events\EditorialEventDates;
use App\Services\Events\EditorialEventEditor;
use App\Support\Editorial\EditorialDesk;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Il modulo evento della redazione nell'app (R3: R-EV-05, 06, 18, 26): nuovo
 * evento, modifica per sezione, locandina e galleria, tag nuovi, scelte dei
 * campi con ricerca, «Programma pubblicazione», «Genera date».
 *
 * Le regole e il salvataggio stanno in `EditorialEventEditor` (lo usa anche
 * `CreateEvent` del pannello), i valori del modulo in `EditorialEventForm`,
 * le date in `EditorialEventDates` (lo usa anche la sezione «Date»). Chi
 * decide è la `EventPolicy`: creare e modificare sono di admin e super admin.
 */
final class EventEditorController extends Controller
{
    /** Le righe delle ricerche dei campi a scelta. */
    private const LOOKUP_LIMIT = 50;

    public function __construct(
        private readonly EditorialEventEditor $editor,
        private readonly EditorialEventForm $form,
    ) {}

    /** Il modulo vuoto: scelte e valori predefiniti di «Nuovo evento». */
    public function create(): JsonResponse
    {
        $desk = EditorialDesk::require();
        Gate::authorize('create', Event::class);

        return response()->json(['data' => ['options' => $this->form->options($desk->user), 'event' => null]]);
    }

    /** Il modulo di un evento: scelte, valori e azioni della testata. */
    public function edit(int $event): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = EventController::findActive($event);
        Gate::authorize('update', $record);

        return response()->json(['data' => ['options' => $this->form->options($desk->user), 'event' => $this->form->values($record, $desk->user)]]);
    }

    /** «Crea»: la scheda con le sue date. Risponde con il modulo dell'evento nato. */
    public function store(Request $request): JsonResponse
    {
        $desk = EditorialDesk::require();
        $event = $this->editor->create($request->all(), $desk->user);

        return response()->json(['data' => $this->form->values($event, $desk->user), 'message' => 'Salvato'], 201);
    }

    /**
     * Gli stati che fermano un evento: annullato (biglietti e prenotazioni
     * annullati, mail agli acquirenti), archiviato, rifiutato. Dal `PATCH`
     * valgono come «Annulla evento» (`events.cancel`): password recente.
     */
    public const STOPPING_STATUSES = [EventStatus::Cancelled, EventStatus::Archived, EventStatus::Rejected];

    /**
     * «Salva»: le chiavi ricevute (una sezione o il modulo intero). Portare
     * l'evento in uno stato che lo ferma chiede la password scritta negli
     * ultimi 15 minuti, come l'azione «Annulla evento».
     */
    public function update(Request $request, int $event, RequireRecentPassword $recentPassword): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = EventController::findActive($event);
        Gate::authorize('update', $record);
        $status = is_string($request->input('status')) ? EventStatus::tryFrom($request->input('status')) : null;
        if ($status !== null && $status !== $record->status && in_array($status, self::STOPPING_STATUSES, true)) {
            $recentPassword->ensure($request);
        }
        $saved = $this->editor->update($record, $request->all(), $desk->user);

        return response()->json(['data' => $this->form->values($saved, $desk->user), 'message' => 'Salvato']);
    }

    /**
     * Il locale è cambiato: «Prima di andare» si ricalcola come nel pannello
     * (via i valori ereditati dal locale di prima, dentro quelli del nuovo).
     */
    public function beforeGoing(Request $request): JsonResponse
    {
        EditorialDesk::require();
        $data = $request->validate([
            'previous_venue_id' => ['nullable', 'integer'],
            'venue_id' => ['nullable', 'integer'],
            'content_details' => ['nullable', 'array'],
        ]);
        $previous = isset($data['previous_venue_id']) ? Venue::query()->find($data['previous_venue_id']) : null;
        $next = isset($data['venue_id']) ? Venue::query()->find($data['venue_id']) : null;
        $details = $this->editor->rebaseBeforeGoing(is_array($data['content_details'] ?? null) ? $data['content_details'] : [], $previous, $next);

        return response()->json(['data' => ['content_details' => $details === [] ? new \stdClass : $details]]);
    }

    /**
     * I locali per i campi «Locale» (della città scelta, `city_id`) e
     * «Locale di questa data» / «Organizzatore registrato» (solo approvati,
     * `approved=1`), in ordine di nome, con la ricerca `q`.
     */
    public function venues(Request $request): JsonResponse
    {
        EditorialDesk::require();
        $q = Str::limit(trim((string) $request->query('q', '')), 120, '');
        $venues = Venue::query()
            ->when($request->integer('city_id') > 0, fn ($query) => $query->where('city_id', $request->integer('city_id')))
            ->when($request->boolean('approved'), fn ($query) => $query->approved())
            ->when($q !== '', fn ($query) => $query->where('name', 'like', '%'.$q.'%'))
            ->with('city')->orderBy('name')->limit(self::LOOKUP_LIMIT)->get();

        return response()->json(['data' => $venues->map(fn (Venue $venue): array => [
            'id' => (int) $venue->getKey(),
            'name' => (string) $venue->name,
            'city' => $venue->city?->name,
            'address' => $venue->address,
            'ticketing_enabled' => (bool) $venue->ticketing_enabled,
        ])->values()->all()]);
    }

    /** Le organizzazioni attive per «Organizzatore registrato» e «Co-organizzatori». */
    public function organizers(Request $request): JsonResponse
    {
        EditorialDesk::require();
        $q = Str::limit(trim((string) $request->query('q', '')), 120, '');
        $organizers = Organizer::query()->where('is_active', true)
            ->when($q !== '', fn ($query) => $query->where('name', 'like', '%'.$q.'%'))
            ->orderBy('name')->limit(self::LOOKUP_LIMIT)->get(['id', 'name']);

        return response()->json(['data' => $organizers->map(fn (Organizer $organizer): array => ['id' => (int) $organizer->getKey(), 'name' => (string) $organizer->name])->values()->all()]);
    }

    /**
     * I tag per il campo «Tag», come la ricerca del pannello
     * (`SharedEventTags`): per nome, cinquanta al massimo, e `can_create` se
     * il nome scritto non esiste ancora.
     */
    public function tags(Request $request): JsonResponse
    {
        EditorialDesk::require();
        $name = Str::squish(strip_tags((string) $request->query('q', '')));
        $tags = Tag::query()->when($name !== '', fn ($query) => $query->where('name', 'like', '%'.$name.'%'))
            ->orderBy('name')->limit(self::LOOKUP_LIMIT)->get(['id', 'name']);
        $canCreate = $name !== '' && mb_strlen($name) <= 80 && Str::slug($name) !== '' && ! Tag::query()->where('slug', Str::slug($name))->exists();

        return response()->json([
            'data' => $tags->map(fn (Tag $tag): array => ['id' => (int) $tag->getKey(), 'name' => (string) $tag->name])->values()->all(),
            'meta' => ['can_create' => $canCreate],
        ]);
    }

    /** «Crea tag» dal campo: il tag condiviso (`CreateSharedEventTag`), legato all'evento se c'è. */
    public function storeTag(Request $request, CreateSharedEventTag $create): JsonResponse
    {
        $desk = EditorialDesk::require();
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'event_id' => ['nullable', 'integer']]);
        $event = isset($data['event_id']) ? EventController::findActive((int) $data['event_id']) : null;
        $tag = $create->execute($desk->user, (string) $data['name'], $event, null);

        return response()->json(['data' => ['id' => (int) $tag->getKey(), 'name' => (string) $tag->name]], 201);
    }

    /** «Locandina»: il file nuovo sostituisce il vecchio (campo multipart `image`). */
    public function storePoster(Request $request, int $event, EditorialEventMedia $media): JsonResponse
    {
        $record = $this->editable($event);
        $request->validate(ImageRules::rules(), [], ImageRules::attributes());
        $file = $request->file('image');
        abort_unless($file instanceof UploadedFile, 422);

        return response()->json(['data' => $media->replacePoster($record, $file)]);
    }

    /** Toglie la locandina. */
    public function removePoster(int $event, EditorialEventMedia $media): JsonResponse
    {
        return response()->json(['data' => $media->removePoster($this->editable($event))]);
    }

    /** Una foto in più nella galleria (campo multipart `image`). */
    public function storeGallery(Request $request, int $event, EditorialEventMedia $media): JsonResponse
    {
        $record = $this->editable($event);
        $request->validate(ImageRules::rules(), [], ImageRules::attributes());
        $file = $request->file('image');
        abort_unless($file instanceof UploadedFile, 422);

        return response()->json(['data' => $media->addToGallery($record, $file)], 201);
    }

    /** Toglie una foto della galleria. */
    public function removeGallery(int $event, int $media, EditorialEventMedia $images): JsonResponse
    {
        return response()->json(['data' => $images->removeFromGallery($this->editable($event), $media)]);
    }

    /** Il nuovo ordine della galleria (`ids`: tutte e sole le foto). */
    public function orderGallery(Request $request, int $event, EditorialEventMedia $media): JsonResponse
    {
        $record = $this->editable($event);
        $data = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer', 'distinct']]);

        return response()->json(['data' => $media->reorderGallery($record, array_map('intval', $data['ids']))]);
    }

    /**
     * «Programma pubblicazione» (`publish_at`, un istante futuro): solo bozze
     * o eventi in attesa con almeno una data.
     */
    public function schedule(Request $request, int $event, ScheduleEventPublication $schedule): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = $this->editable($event);
        $data = $request->validate(['publish_at' => ['required', 'date', 'after:now']], [], ['publish_at' => 'Data e ora di pubblicazione']);
        $schedule->schedule($record, $desk->user, CarbonImmutable::parse((string) $data['publish_at'])->utc());
        $record->refresh();

        return response()->json([
            'data' => $this->form->values($record, $desk->user),
            'message' => $record->status === EventStatus::Pending ? 'In attesa di approvazione della redazione.' : 'Pubblicazione automatica all’orario scelto.',
        ]);
    }

    /** «Annulla programmazione». */
    public function unschedule(int $event, ScheduleEventPublication $schedule): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = $this->editable($event);
        $schedule->cancel($record, $desk->user);

        return response()->json(['data' => $this->form->values($record->refresh(), $desk->user), 'message' => 'Programmazione annullata']);
    }

    /** «Genera date» dalle ricorrenze: quante ne sono nate. */
    public function generate(int $event, EditorialEventDates $dates): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = $this->editable($event);
        abort_unless($record->recurrences()->exists(), 404);
        $created = $dates->generate($record);

        return response()->json([
            'data' => $this->form->values($record->refresh(), $desk->user),
            'meta' => ['created' => $created],
            'message' => $created === 0 ? __('admin.notifications.nothing_to_do') : __('admin.notifications.occurrences_generated', ['count' => $created]),
        ]);
    }

    /** Un evento non cestinato che chi chiede può modificare. */
    private function editable(int $id): Event
    {
        EditorialDesk::require();
        $event = EventController::findActive($id);
        Gate::authorize('update', $event);

        return $event;
    }
}
