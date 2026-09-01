<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Enums\VenueStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EventQueryRequest;
use App\Http\Requests\Api\V1\VenueQueryRequest;
use App\Http\Resources\V1\OccurrenceResource;
use App\Http\Resources\V1\VenueResource;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Services\Api\OccurrenceFeed;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * I locali (§13.1).
 *
 * Un locale **sospeso** resta raggiungibile — chi ha il collegamento non deve
 * trovare un buco — ma la sua scheda lo dichiara nello stato; un locale in
 * bozza, rifiutato o cancellato non esiste per l'API.
 *
 * Il conteggio delle date future arriva dal motore temporale
 * (`countsByVenue()`), che è anche ciò che garantisce che il numero mostrato
 * dall'app e quello del sito siano lo stesso numero.
 */
final class VenueController extends Controller
{
    use InteractsWithApi;

    /**
     * Gli stati che il pubblico può vedere.
     */
    private const VISIBLE = [VenueStatus::Approved, VenueStatus::Suspended];

    public function __construct(private readonly OccurrenceFeed $feed) {}

    public function index(VenueQueryRequest $request): JsonResponse
    {
        $city = $this->city();
        $term = $request->term();
        $type = $request->type();
        $municipality = $request->municipality();
        $since = $request->updatedSince();

        $paginator = Venue::query()
            ->with('media')
            ->inCity($city)
            ->approved()
            ->when($type !== null, fn (Builder $query): Builder => $query->where('type', $type))
            ->when($municipality !== null, fn (Builder $query): Builder => $query->where('municipality', $municipality))
            ->when($since !== null, fn (Builder $query): Builder => $query->where('updated_at', '>=', $since))
            ->when($term !== '', fn (Builder $query): Builder => $query->where(function (Builder $match) use ($term): void {
                $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

                $match->where('name', 'like', $pattern)
                    ->orWhere('short_description', 'like', $pattern)
                    ->orWhere('municipality', 'like', $pattern);
            }))
            ->orderBy('name')
            ->orderBy('id')
            ->cursorPaginate(perPage: $request->limit(), cursor: $request->cursor())
            ->withQueryString();

        $counts = EventOccurrenceQuery::for($city)->upcoming()->countsByVenue();
        $timezone = $city->timezone;

        return ApiResponse::page(
            $paginator,
            static fn (Venue $venue): array => [
                ...VenueResource::toArray($venue, $timezone),
                'upcoming_occurrences' => $counts[(int) $venue->getKey()] ?? 0,
            ],
        );
    }

    public function show(string $slug): JsonResponse
    {
        $city = $this->city();
        $venue = $this->findVisible($city, $slug);

        $counts = EventOccurrenceQuery::for($city)->upcoming()->atVenue($venue)->count();

        return ApiResponse::item([
            ...VenueResource::toArray($venue, $city->timezone),
            'upcoming_occurrences' => $counts,
        ]);
    }

    /**
     * Le date di un locale: gli stessi parametri di `GET /v1/events` con il
     * locale già scelto, così chi sa leggere una lista le sa leggere tutte.
     */
    public function events(EventQueryRequest $request, string $slug): JsonResponse
    {
        $city = $this->city();
        $venue = $this->findVisible($city, $slug);

        $query = $this->feed->query($city, $request)->atVenue($venue);

        $page = $this->feed->page($city, $request, $this->currentUser($request), $query);

        return ApiResponse::page(
            $page->paginator,
            static fn (EventOccurrence $occurrence): array => OccurrenceResource::toArray($occurrence, $page->context),
        );
    }

    /**
     * L'archivio: le date gia passate di un locale.
     *
     * §11.9 lo tiene sul sito perche e «ottimo per la ricerca organica» — le
     * pagine di cio che e stato restano indicizzate e portano visite. Un
     * client che vuole mostrare la stessa scheda deve poterlo leggere, o la
     * sua versione del locale sara sempre piu povera di quella del sito.
     */
    public function past(EventQueryRequest $request, string $slug): JsonResponse
    {
        $city = $this->city();
        $venue = $this->findVisible($city, $slug);

        $query = EventOccurrenceQuery::for($city)->atVenue($venue)->past();

        $page = $this->feed->page($city, $request, $this->currentUser($request), $query);

        return ApiResponse::page(
            $page->paginator,
            static fn (EventOccurrence $occurrence): array => OccurrenceResource::toArray($occurrence, $page->context),
        );
    }

    private function findVisible(City $city, string $slug): Venue
    {
        $venue = Venue::query()
            ->with('media')
            ->inCity($city)
            ->whereIn('status', self::VISIBLE)
            ->where('slug', $slug)
            ->first();

        if (! $venue instanceof Venue) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        return $venue;
    }
}
