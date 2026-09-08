<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SearchRequest;
use App\Http\Resources\V1\OccurrenceResource;
use App\Http\Resources\V1\TagResource;
use App\Http\Resources\V1\VenueResource;
use App\Models\EventOccurrence;
use App\Models\Tag;
use App\Models\Venue;
use App\Services\Api\OccurrenceFeed;
use App\Services\Search\SiteSearch;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * `GET /v1/search` (§13.1): eventi, locali e tag in una risposta sola.
 *
 * Chi cerca "capannone" non sa ancora se sia il nome di un locale, di un
 * evento o di un tag: dividere la ricerca in tre chiamate significherebbe
 * farlo scegliere prima di sapere. Il raggruppamento è per tipo, non per
 * punteggio, perché "tre locali e due date" si legge, mentre un elenco misto
 * ordinato per rilevanza no.
 *
 * Scout dice **quali eventi** somigliano al testo; quali date siano ancora
 * future lo dice il motore temporale (§8.1), e non c'è altro posto in cui
 * quella definizione esista.
 */
final class SearchController extends Controller
{
    use InteractsWithApi;

    public function __construct(
        private readonly SiteSearch $search,
        private readonly OccurrenceFeed $feed,
    ) {}

    public function __invoke(SearchRequest $request): JsonResponse
    {
        $city = $this->city();
        $term = $request->term();
        $limit = $request->limit();

        $occurrences = $this->search->events($city, $term, $limit);
        $includes = $request->includes();

        $this->feed->hydrate($occurrences, $includes);

        $context = ApiContext::forOccurrences(
            $city,
            $includes,
            $this->currentUser($request),
            $this->feed->ids($occurrences),
        );

        $timezone = $city->timezone;

        return ApiResponse::collection([
            'organizers' => $this->search->organizers($city, $term, $limit)->map(fn ($organizer) => [
                'id' => $organizer->id, 'slug' => $organizer->slug, 'name' => $organizer->name, 'url' => route('organizers.show', $organizer),
            ])->all(),
            'events' => $occurrences
                ->map(static fn (EventOccurrence $occurrence): array => OccurrenceResource::toArray($occurrence, $context))
                ->all(),
            'venues' => $this->search->venues($city, $term, $limit)
                ->map(static fn (Venue $venue): array => VenueResource::summary($venue))
                ->all(),
            'tags' => $this->search->tags($term, $limit)
                ->map(static fn (Tag $tag): array => TagResource::toArray($tag))
                ->all(),
        ], ['query' => $term, 'timezone' => $timezone]);
    }
}
