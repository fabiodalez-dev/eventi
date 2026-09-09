<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EventQueryRequest;
use App\Http\Resources\V1\EventResource;
use App\Http\Resources\V1\OccurrenceResource;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Queries\EventOccurrenceQuery;
use App\Services\Api\OccurrenceFeed;
use App\Services\Search\ContextualFacets;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * Gli eventi dell'API (§13.2).
 *
 * `index()` restituisce **occorrenze**, non eventi: un evento con dieci date
 * è dieci elementi, perché è la data che si mette in agenda. `show()` fa il
 * contrario ed è l'unico posto in cui l'evento torna a essere una scheda con
 * le sue date dentro.
 */
final class EventController extends Controller
{
    use InteractsWithApi;

    public function __construct(private readonly OccurrenceFeed $feed) {}

    public function index(EventQueryRequest $request): JsonResponse
    {
        $page = $this->feed->page($this->city(), $request, $this->currentUser($request));

        return ApiResponse::page(
            $page->paginator,
            static fn (EventOccurrence $occurrence): array => OccurrenceResource::toArray($occurrence, $page->context),
        );
    }

    public function facets(EventQueryRequest $request): JsonResponse
    {
        return ApiResponse::item(array_map(fn (array $counts): object => (object) $counts, app(ContextualFacets::class)->build(
            $this->city(), $request->filters(), fn () => $this->feed->query($this->city(), $request),
        )));
    }

    public function show(EventQueryRequest $request, string $slug): JsonResponse
    {
        $city = $this->city();
        $event = $this->findReadable($city, $slug);

        /*
         * Le date della scheda le sceglie il motore: sono le future, e sono
         * future secondo la definizione unica di §8.1. Se non ce n'è più
         * nessuna la scheda resta legittima — ci arrivano i collegamenti
         * condivisi mesi prima — e mostra le ultime passate.
         */
        $occurrences = EventOccurrenceQuery::for($city)->forEvent($event)->upcoming()->get();

        if ($occurrences->isEmpty()) {
            $occurrences = EventOccurrenceQuery::for($city)
                ->forEvent($event)
                ->past()
                ->orderByNewestFirst()
                ->get()
                ->take(3);
        }

        /** @var Collection<int, EventOccurrence> $occurrences */
        $occurrences = $occurrences->take(config()->integer('api.limits.event_dates'))->values();

        $includes = $request->includes();
        $this->feed->hydrate($occurrences, $includes);

        $context = ApiContext::forOccurrences(
            $city,
            $includes,
            $this->currentUser($request),
            $this->feed->ids($occurrences),
        );

        return ApiResponse::item(EventResource::toArray($event, $occurrences, $context));
    }

    /**
     * Eventi simili: stessa categoria, prossime date, questo escluso — la
     * stessa definizione della scheda del sito, perché due risposte diverse
     * alla stessa domanda sono un difetto anche quando entrambe sono sensate.
     */
    public function similar(EventQueryRequest $request, string $slug): JsonResponse
    {
        $city = $this->city();
        $event = $this->findReadable($city, $slug);

        $query = EventOccurrenceQuery::for($city)
            ->upcoming()
            ->excludingEvent($event)
            ->orderByRelevance();

        if ($event->category !== null) {
            $query->inCategories([$event->category]);
        }

        $page = $this->feed->page($city, $request, $this->currentUser($request), $query);

        return ApiResponse::page(
            $page->paginator,
            static fn (EventOccurrence $occurrence): array => OccurrenceResource::toArray($occurrence, $page->context),
        );
    }

    /**
     * TUTTE le date future di un evento, non solo la prima.
     *
     * §11.5 le vuole sulla scheda, e §15.3 ne ha bisogno per il selettore che
     * compare quando si salva un evento con piu repliche: si salva
     * l'occorrenza, non l'evento, e senza questo elenco un client dovrebbe
     * dedurre da se quali date esistono — ricostruendo una logica che §13.2
     * gli vieta proprio per non farla divergere.
     */
    public function occurrences(EventQueryRequest $request, string $slug): JsonResponse
    {
        $city = $this->city();
        $event = $this->findReadable($city, $slug);

        $query = EventOccurrenceQuery::for($city)
            ->forEvent($event)
            ->upcoming();

        $page = $this->feed->page($city, $request, $this->currentUser($request), $query);

        return ApiResponse::page(
            $page->paginator,
            static fn (EventOccurrence $occurrence): array => OccurrenceResource::toArray($occurrence, $page->context),
        );
    }

    /**
     * Pubblicati **e archiviati** (§14.5), come il sito: §18 scenario G vuole
     * che i due diano la stessa risposta nello stesso istante, e una scheda
     * che il sito mostra e l'API dichiara inesistente sarebbe la divergenza
     * più difficile da spiegare a chi scrive l'applicazione.
     */
    private function findReadable(City $city, string $slug): Event
    {
        $event = Event::query()
            ->with([
                'venue', 'category', 'tags', 'media', 'ticketTiers',
                /* Le sole campagne vive: la risorsa non filtra, mostra cio'
                   che trova caricato. Caricarle tutte significherebbe
                   dichiarare sponsorizzato un evento la cui campagna e'
                   finita a marzo. */
                'sponsorships' => fn ($query) => $query->visible(),
            ])
            ->inCity($city)
            ->readable()
            ->where('slug', $slug)
            ->first();

        if (! $event instanceof Event) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        return $event;
    }
}
