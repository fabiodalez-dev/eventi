<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Actions\PublishEventSubmission;
use App\Actions\RejectEventSubmission;
use App\Enums\ApiErrorCode;
use App\Enums\Permission;
use App\Enums\PriceType;
use App\Enums\SubmissionStatus;
use App\Enums\VenueStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Events\EditorialListRequest;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use App\Models\Venue;
use App\Support\Description;
use App\Support\Editorial\EditorialDesk;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Le proposte di eventi dal pubblico (R-SB-01…05), come
 * `/admin/event-submissions`: l'elenco con il filtro di stato, l'esame con
 * i dati ricevuti e il modulo dell'evento da pubblicare, «Crea e pubblica»
 * (`PublishEventSubmission`) e «Rifiuta proposta» con il motivo interno
 * (`RejectEventSubmission`).
 *
 * Le vede chi modera gli eventi (`EventSubmissionPolicy`); «Crea e
 * pubblica» chiede in più `events.publish` e `EventPolicy::create`, come il
 * pannello: il moderatore esamina e rifiuta, non pubblica.
 */
final class SubmissionController extends Controller
{
    /**
     * L'elenco, dalla più recente. `filter[status]`: `pending`, `approved`,
     * `rejected`; `q` cerca nel titolo.
     */
    public function index(EditorialListRequest $request): JsonResponse
    {
        $desk = EditorialDesk::require();
        Gate::authorize('viewAny', EventSubmission::class);
        $query = EventSubmission::query()->with('city')->orderByDesc('created_at')->orderByDesc('id');
        if (($status = SubmissionStatus::tryFrom((string) ($request->filters()['status'] ?? ''))) !== null) {
            $query->ofStatus($status);
        }
        if (($search = $request->search()) !== null) {
            $query->where('title', 'like', '%'.addcslashes($search, '%_\\').'%');
        }
        $page = $query->paginate($request->perPage(), page: $request->page());

        return response()->json([
            'data' => collect($page->items())->map(fn (EventSubmission $submission): array => $this->row($submission, $desk->user))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * «Esamina»: i dati ricevuti (chi propone, contatto, luogo indicato,
     * testo, note), il modulo già compilato con i valori del pannello
     * (prezzo «Non indicato»), le scelte (locali approvati della città della
     * proposta, categorie attive, ingressi) e le azioni permesse.
     */
    public function show(int $submission): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = self::find($submission);
        Gate::authorize('view', $record);
        $timezone = $record->city->timezone ?? config()->string('app.timezone');
        $pending = $record->status === SubmissionStatus::Pending;

        return response()->json(['data' => [
            ...$this->row($record, $desk->user),
            'contact_email' => $record->contact_email,
            'raw_text' => $record->raw_text,
            'raw_text_html' => Description::render($record->raw_text)->toHtml(),
            'notes' => $record->notes,
            'reviewed_at' => $record->reviewed_at?->utc()->toIso8601String(),
            'form' => $pending ? [
                'title' => $record->title,
                'raw_text' => $record->raw_text,
                'venue_id' => $record->venue_id === null ? null : (int) $record->venue_id,
                'category_id' => null,
                'starts_at_hint' => $record->starts_at_hint?->utc()->toIso8601String(),
                'ends_at' => null,
                'price_type' => PriceType::Unknown->value,
                'price_min' => null,
            ] : null,
            'choices' => $pending ? [
                'venues' => Venue::query()->where('city_id', $record->city_id)->where('status', VenueStatus::Approved)->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Venue $venue): array => ['value' => (string) $venue->getKey(), 'label' => $venue->name])->values()->all(),
                'categories' => Category::query()->active()->ordered()->get(['id', 'name'])
                    ->map(fn (Category $category): array => ['value' => (string) $category->getKey(), 'label' => $category->name])->values()->all(),
                'price_types' => collect(PriceType::options())->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            ] : null,
            'timezone' => $timezone,
            'limits' => ['title' => 255, 'raw_text' => 50000, 'notes' => RejectEventSubmission::NOTES_MAX],
        ]]);
    }

    /**
     * «Crea e pubblica»: l'evento nasce dalla proposta con i campi del
     * modulo (`title`, `raw_text`, `venue_id`, `category_id`,
     * `starts_at_hint`, `ends_at`, `price_type`, `price_min`) e va online
     * subito. Le date si mandano in ISO 8601 con il fuso (o nell'ora della
     * città della proposta senza fuso). Risponde con l'id dell'evento (201).
     */
    public function publish(Request $request, int $submission, PublishEventSubmission $action): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = self::find($submission);
        $this->authorizePublish($record, $desk->user);
        $timezone = $record->city->timezone ?? config()->string('app.timezone');
        $data = $request->only(['title', 'raw_text', 'venue_id', 'category_id', 'starts_at_hint', 'ends_at', 'price_type', 'price_min']);
        foreach (['starts_at_hint', 'ends_at'] as $field) {
            $data[$field] = self::utc($data[$field] ?? null, $timezone, $field);
        }
        try {
            $event = $action->handle($record, $desk->user, $data);
        } catch (ValidationException $error) {
            /* Il modulo del pannello si chiama `data.*`: l'app riceve i nomi dei campi. */
            $fields = [];
            foreach ($error->errors() as $key => $messages) {
                $fields[str_replace('data.', '', (string) $key)] = $messages;
            }
            throw ValidationException::withMessages($fields);
        }

        return response()->json([
            'data' => ['event_id' => (int) $event->getKey(), 'submission' => $this->row($record->refresh(), $desk->user)],
            'message' => __('editorial-events.submission_published'),
        ], 201);
    }

    /** «Rifiuta proposta» con il motivo interno (`notes`, obbligatorio, fino a 2000 caratteri). */
    public function reject(Request $request, int $submission, RejectEventSubmission $action): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = self::find($submission);
        Gate::authorize('update', $record);
        if ($record->status !== SubmissionStatus::Pending) {
            throw new ApiException(ApiErrorCode::Conflict, __('editorial-events.submission_already_reviewed'));
        }
        $data = $request->validate(['notes' => ['required', 'string', 'max:'.RejectEventSubmission::NOTES_MAX]]);
        if (! $action->handle($record, $desk->user, (string) $data['notes'])) {
            throw new ApiException(ApiErrorCode::Conflict, __('editorial-events.submission_already_reviewed'));
        }

        return response()->json(['data' => $this->row($record->refresh(), $desk->user), 'message' => __('editorial-events.submission_rejected')]);
    }

    /** @return array<string, mixed> */
    private function row(EventSubmission $submission, User $user): array
    {
        $pending = $submission->status === SubmissionStatus::Pending;

        return [
            'id' => (int) $submission->getKey(),
            'title' => $submission->title,
            'contact_name' => $submission->contact_name,
            'starts_at_hint' => $submission->starts_at_hint?->utc()->toIso8601String(),
            'venue_hint' => $submission->venue_hint,
            'city' => $submission->city === null ? null : ['id' => (int) $submission->city->getKey(), 'name' => $submission->city->name, 'timezone' => $submission->city->timezone],
            'status' => ['value' => $submission->status->value, 'label' => $submission->status->label()],
            'created_at' => $submission->getAttribute('created_at') instanceof \DateTimeInterface ? CarbonImmutable::instance($submission->getAttribute('created_at'))->utc()->toIso8601String() : null,
            'event_id' => $submission->event_id === null ? null : (int) $submission->event_id,
            'abilities' => [
                'publish' => $pending && $this->canPublish($user),
                'reject' => $pending && Gate::forUser($user)->allows('update', $submission),
                'open_event' => $submission->event_id !== null,
            ],
        ];
    }

    /** La condizione del pulsante «Crea e pubblica» del pannello. */
    private function canPublish(User $user): bool
    {
        return Gate::forUser($user)->allows('update', new EventSubmission)
            && $user->can(Permission::PublishEvents->value) && Gate::forUser($user)->allows('create', Event::class);
    }

    private function authorizePublish(EventSubmission $submission, User $user): void
    {
        Gate::forUser($user)->authorize('update', $submission);
        if (! $this->canPublish($user)) {
            throw new ApiException(ApiErrorCode::Forbidden);
        }
        if ($submission->status !== SubmissionStatus::Pending && $submission->event_id === null) {
            throw new ApiException(ApiErrorCode::Conflict, __('editorial-events.submission_already_reviewed'));
        }
    }

    /**
     * Una data del modulo in UTC, come la scrive il selettore del pannello
     * prima di passarla a `PublishEventSubmission`: con il fuso si converte,
     * senza si legge nell'ora della città.
     */
    private static function utc(mixed $value, string $timezone, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            throw ValidationException::withMessages([$field => __('validation.date', ['attribute' => $field])]);
        }
        try {
            $hasZone = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) === 1;
            $date = $hasZone ? CarbonImmutable::parse($value) : CarbonImmutable::parse($value, $timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('validation.date', ['attribute' => $field])]);
        }

        return $date->utc()->format('Y-m-d H:i:s');
    }

    private static function find(int $id): EventSubmission
    {
        $submission = EventSubmission::query()->with('city')->find($id);
        if (! $submission instanceof EventSubmission) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        return $submission;
    }
}
