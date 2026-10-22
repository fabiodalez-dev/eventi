<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\Account\AddCollectionItemRequest;
use App\Http\Requests\Web\Account\StoreCollectionRequest;
use App\Models\EventCollection;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Support\Api\ApiResponse;
use App\Support\EditorContent;
use App\Support\EventUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Le raccolte di una persona: si creano, si riempiono di date, si rendono
 * pubbliche con un link, si richiudono. Lo stesso controller serve il sito e
 * l'API (`/v1/me/collections`).
 */
final class CollectionController extends Controller
{
    use InteractsWithCity;

    public function index(Request $request): View|JsonResponse
    {
        $user = $this->user($request);
        $collections = $user->eventCollections()->where('city_id', $this->city()->getKey())->withCount('items')->orderByDesc('id')->get();

        if ($request->is('api/*')) {
            return ApiResponse::collection($collections->map(fn (EventCollection $collection): array => self::toArray($collection))->all());
        }

        return view('account.collections.index', [
            'collections' => $collections,
            'meta' => new PageMeta(title: __('collections.title'), heading: __('collections.title'), indexable: false),
        ]);
    }

    public function store(StoreCollectionRequest $request): RedirectResponse|JsonResponse
    {
        $user = $this->user($request);
        if ($user->eventCollections()->count() >= EventCollection::MAX_PER_USER) {
            throw ValidationException::withMessages(['name' => __('collections.limit', ['max' => EventCollection::MAX_PER_USER])]);
        }
        $collection = $user->eventCollections()->create([
            'city_id' => $this->city()->getKey(),
            'name' => EditorContent::clean('name', (string) $request->validated('name')),
            'description' => filled($request->validated('description')) ? EditorContent::clean('note', (string) $request->validated('description')) : null,
            'is_public' => $request->boolean('is_public'),
        ]);
        if ($request->filled('occurrence_id')) {
            $this->attach($collection, (int) $request->validated('occurrence_id'));
        }

        return $request->is('api/*')
            ? ApiResponse::item(self::toArray($collection->loadCount('items')), status: 201)
            : redirect()->route('account.collections.show', $collection)->with('status', __('collections.created'));
    }

    public function show(Request $request, EventCollection $collection): View|JsonResponse
    {
        Gate::authorize('update', $collection);
        $occurrences = $this->occurrences($collection);

        if ($request->is('api/*')) {
            return ApiResponse::item([...self::toArray($collection->loadCount('items')), 'items' => $this->itemsPayload($collection, $occurrences)]);
        }

        return view('account.collections.show', [
            'collection' => $collection,
            'occurrences' => $occurrences,
            'meta' => new PageMeta(title: $collection->name, heading: $collection->name, indexable: false),
        ]);
    }

    public function update(StoreCollectionRequest $request, EventCollection $collection): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $collection);
        $collection->update([
            'name' => EditorContent::clean('name', (string) $request->validated('name')),
            'description' => filled($request->validated('description')) ? EditorContent::clean('note', (string) $request->validated('description')) : null,
            'is_public' => $request->boolean('is_public'),
        ]);

        return $request->is('api/*')
            ? ApiResponse::item(self::toArray($collection->loadCount('items')))
            : back()->with('status', __('collections.updated'));
    }

    public function destroy(Request $request, EventCollection $collection): RedirectResponse|JsonResponse
    {
        Gate::authorize('delete', $collection);
        $collection->delete();

        return $request->is('api/*')
            ? response()->json(null, 204)
            : redirect()->route('account.collections.index')->with('status', __('collections.deleted'));
    }

    public function addItem(AddCollectionItemRequest $request, EventCollection $collection): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $collection);
        $this->attach($collection, (int) $request->validated('occurrence_id'), $request->validated('note'));

        return $request->is('api/*')
            ? ApiResponse::item(self::toArray($collection->loadCount('items')), status: 201)
            : back()->with('status', __('collections.item_added', ['name' => $collection->name]));
    }

    public function removeItem(Request $request, EventCollection $collection, int $occurrence): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $collection);
        $collection->items()->where('occurrence_id', $occurrence)->delete();

        return $request->is('api/*')
            ? response()->json(null, 204)
            : back()->with('status', __('collections.item_removed'));
    }

    private function attach(EventCollection $collection, int $occurrenceId, ?string $note = null): void
    {
        $occurrence = EventOccurrenceQuery::for($this->city())->forOccurrence($occurrenceId)->get()->first();
        if (! $occurrence instanceof EventOccurrence) {
            throw ValidationException::withMessages(['occurrence_id' => __('collections.unknown_date')]);
        }
        if ($collection->items()->count() >= EventCollection::MAX_ITEMS) {
            throw ValidationException::withMessages(['occurrence_id' => __('collections.items_limit', ['max' => EventCollection::MAX_ITEMS])]);
        }
        $collection->items()->firstOrCreate(['occurrence_id' => $occurrence->getKey()], [
            'note' => filled($note) ? EditorContent::clean('note', (string) $note) : null,
            'position' => (int) $collection->items()->max('position') + 1,
        ]);
    }

    /** @return Collection<int, EventOccurrence> */
    private function occurrences(EventCollection $collection): Collection
    {
        $ids = $collection->items()->pluck('occurrence_id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($ids === []) {
            return new Collection;
        }
        $rows = EventOccurrenceQuery::archiveFor($this->city())->forOccurrences($ids)->get()->keyBy('id');
        /** @var Collection<int, EventOccurrence> $ordered */
        $ordered = new Collection(collect($ids)->map(fn (int $id) => $rows->get($id))->filter()->values()->all());
        $ordered->load(['event.venue', 'event.category', 'event.media', 'venue']);

        return $ordered;
    }

    /**
     * @param  Collection<int, EventOccurrence>  $occurrences
     * @return list<array<string, mixed>>
     */
    private function itemsPayload(EventCollection $collection, Collection $occurrences): array
    {
        $notes = $collection->items()->pluck('note', 'occurrence_id');

        return $occurrences->map(fn (EventOccurrence $occurrence): array => [
            'occurrence_id' => $occurrence->getKey(),
            'title' => $occurrence->event?->title,
            'starts_at' => $occurrence->starts_at?->toIso8601String(),
            'venue' => $occurrence->effectiveVenue()?->name,
            'url' => EventUrl::occurrence($occurrence),
            'note' => $notes[$occurrence->getKey()] ?? null,
        ])->all();
    }

    /** @return array<string, mixed> */
    public static function toArray(EventCollection $collection): array
    {
        return [
            'id' => $collection->getKey(),
            'name' => $collection->name,
            'description' => $collection->description,
            'is_public' => $collection->is_public,
            'public_url' => $collection->is_public ? $collection->publicUrl() : null,
            'items_count' => (int) ($collection->items_count ?? $collection->items()->count()),
            'created_at' => $collection->created_at?->toIso8601String(),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
