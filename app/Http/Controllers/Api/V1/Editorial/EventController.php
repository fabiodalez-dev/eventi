<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Events\EditorialListRequest;
use App\Http\Resources\V1\EventResource;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Services\Api\OccurrenceFeed;
use App\Services\Editorial\EditorialEvents;
use App\Services\Editorial\EditorialEventsExport;
use App\Support\Api\ApiContext;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * L'elenco e la scheda degli eventi della redazione (R2: R-EV-01…04, 07,
 * 16, R-WB-01): le stesse schede, filtri, ricerca e ordinamento della
 * tabella di `/admin/events`, la scheda in sola lettura e l'anteprima della
 * pagina pubblica anche per un evento non pubblicato.
 *
 * Il pannello mostra anche gli eventi cestinati (filtro «Cestinati»): qui si
 * aprono per numero anche da cestinati, e le azioni lo sanno.
 */
final class EventController extends Controller
{
    public function __construct(private readonly EditorialEvents $events) {}

    /**
     * L'elenco, venti righe alla volta.
     *
     * `tab`: `pending`, `published`, `draft`, `cancelled` (senza: tutti).
     * `filter[…]`: `status`, `city`, `venue`, `category`, `source`,
     * `is_featured` (`1`/`0`), `published_today`, `published_this_week`,
     * `missing_poster`, `incomplete`, `possible_duplicates` (`1`), `trashed`
     * (`with`, `only`). `q` cerca nel titolo e nel nome del locale. `sort`:
     * `title`, `published_at`, `created_at` (con `-` decrescente; senza, i più
     * recenti prima). `meta.tabs` porta i conteggi delle schede.
     */
    public function index(EditorialListRequest $request): JsonResponse
    {
        $desk = EditorialDesk::require();
        Gate::authorize('viewAny', Event::class);
        $city = self::workCity($desk);
        $page = $this->events->query($city, $request->tab(), $request->filters(), $request->search(), $request->sort())
            ->paginate($request->perPage(), page: $request->page());

        return response()->json([
            'data' => collect($page->items())->map(fn (Event $event): array => $this->events->row($event, $desk->user))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'tabs' => $this->events->tabCounts($city, $request->filters(), $request->search()),
            ],
        ]);
    }

    /**
     * Le scelte dei filtri che non stanno in `GET options`: città,
     * categorie, locali e provenienze, con le etichette del pannello.
     */
    public function filters(): JsonResponse
    {
        EditorialDesk::require();
        $choice = fn (int|string $value, string $label): array => ['value' => (string) $value, 'label' => $label];

        return response()->json(['data' => [
            'cities' => City::query()->orderBy('name')->get(['id', 'name'])->map(fn (City $city): array => $choice($city->getKey(), $city->name))->values()->all(),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name'])->map(fn (Category $category): array => $choice($category->getKey(), $category->name))->values()->all(),
            'venues' => Venue::query()->orderBy('name')->get(['id', 'name'])->map(fn (Venue $venue): array => $choice($venue->getKey(), $venue->name))->values()->all(),
            'sources' => collect(EventSource::options())->map(fn (string $label, string $value): array => $choice($value, $label))->values()->all(),
            'statuses' => collect(EventStatus::options())->map(fn (string $label, string $value): array => $choice($value, $label))->values()->all(),
        ]]);
    }

    /** Il foglio Excel delle righe che si stanno guardando (stessi criteri dell'elenco). */
    public function export(EditorialListRequest $request, EditorialEventsExport $export): BinaryFileResponse
    {
        $desk = EditorialDesk::require();
        Gate::authorize('viewAny', Event::class);

        return $export->download($this->events->query(self::workCity($desk), $request->tab(), $request->filters(), $request->search(), $request->sort()));
    }

    /**
     * La scheda in sola lettura: dati, date nel fuso della città, il
     * riquadro «Redazione», le azioni permesse (`abilities`), i collegamenti
     * (`links`: percorsi del pannello da aprire sul sito, o l'indirizzo
     * pubblico) e quanto tocca un annullamento (`cancel_impact`).
     */
    public function show(int $event): JsonResponse
    {
        $desk = EditorialDesk::require();
        $record = self::find($event);
        Gate::authorize('view', $record);

        return response()->json(['data' => $this->events->detail($record, $desk->user)]);
    }

    /**
     * «Anteprima della pagina» (R-EV-16, R-WB-01): la forma di
     * `GET /events/{slug}` anche per un evento non pubblicato, con le date
     * future (o le ultime tre passate).
     */
    public function preview(Request $request, int $event, OccurrenceFeed $feed): JsonResponse
    {
        $record = self::find($event);
        Gate::authorize('view', $record);
        $record->load(['venue', 'category', 'tags', 'city', 'media', 'ticketTiers']);
        $occurrences = EventOccurrenceQuery::managementFor($record)->upcoming()->get()->take(config()->integer('api.limits.event_dates'));
        if ($occurrences->isEmpty()) {
            $occurrences = EventOccurrenceQuery::managementFor($record)->past()->orderByNewestFirst()->get()->take(3);
        }
        $occurrences = $occurrences->values();
        $feed->hydrate($occurrences, []);
        $occurrences->loadMissing('ticketTiers');
        $user = $request->user();
        $context = ApiContext::forOccurrences($record->city, [], $user instanceof User ? $user : null, $feed->ids($occurrences));

        return response()->json(['data' => EventResource::toArray($record, $occurrences, $context)]);
    }

    /** Un evento per numero, anche cestinato (il pannello li apre dal filtro «Cestinati»). */
    public static function find(int $id): Event
    {
        $event = Event::withTrashed()->find($id);
        if (! $event instanceof Event) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        return $event;
    }

    /** Un evento non cestinato: le decisioni non si prendono su una scheda nel cestino. */
    public static function findActive(int $id): Event
    {
        $event = self::find($id);
        if ($event->trashed()) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        return $event;
    }

    /** La città da cui si misura «oggi»: quella di lavoro, altrimenti la prima (`EventResource::listCity`). */
    public static function workCity(EditorialDesk $desk): City
    {
        return $desk->city ?? City::query()->orderBy('id')->firstOrFail();
    }
}
