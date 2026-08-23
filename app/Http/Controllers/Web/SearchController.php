<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\EventFilterRequest;
use App\Models\City;
use App\Queries\EventOccurrenceQuery;
use App\Services\Search\FilterFacets;
use App\Services\Search\SiteSearch;
use Illuminate\Contracts\View\View;

/**
 * La ricerca del sito (`/cerca`).
 *
 * I risultati sono **raggruppati per tipo** — eventi, locali, tag — perché
 * chi scrive "jazz" può cercare tre cose diverse: una serata, il circolo che
 * le organizza o l'etichetta con cui trovarle tutte. Una lista unica
 * mescolata costringerebbe a leggerle tutte per capire quale sia quale.
 *
 * Il gruppo vuoto non si disegna (§8.6), e quando non c'è proprio nulla la
 * pagina non resta spoglia: propone le strade che sicuramente portano da
 * qualche parte — le categorie che hanno eventi in programma, i tag più usati,
 * le finestre di oggi e del weekend.
 */
final class SearchController extends Controller
{
    use InteractsWithCity;

    private const EVENTS = 12;

    private const VENUES = 6;

    private const TAGS = 12;

    /**
     * Quante categorie proporre a chi non ha trovato niente.
     */
    private const SUGGESTED_CATEGORIES = 8;

    public function __construct(
        private readonly SiteSearch $search,
        private readonly FilterFacets $facets,
    ) {}

    public function __invoke(EventFilterRequest $request): View
    {
        $city = $this->city();
        $term = $request->filters()->q;

        $events = $term === '' ? null : $this->search->events($city, $term, self::EVENTS);
        $venues = $term === '' ? null : $this->search->venues($city, $term, self::VENUES);
        $tags = $term === '' ? null : $this->search->tags($term, self::TAGS);

        $found = ($events?->count() ?? 0) + ($venues?->count() ?? 0) + ($tags?->count() ?? 0);

        return view('search.index', [
            'city' => $city,
            'term' => $term,
            'events' => $events,
            'venues' => $venues,
            'tags' => $tags,
            'found' => $found,
            /* I suggerimenti si calcolano solo quando servono davvero: una
               ricerca riuscita non ha bisogno di alternative. */
            'suggestions' => $found === 0 ? $this->suggestions($city) : [],
            'meta' => $this->meta($city, $term, $found),
        ]);
    }

    /**
     * Le strade che portano di sicuro da qualche parte: le categorie che hanno
     * davvero eventi in programma (§8.6 vale anche qui — non si propone una
     * casella che porta a una lista vuota) e i tag più usati.
     *
     * @return array{categories: list<array{name: string, url: string, count: int}>, tags: list<array{name: string, url: string}>}
     */
    private function suggestions(City $city): array
    {
        $counts = EventOccurrenceQuery::for($city)->upcoming()->countsByCategory();

        $categories = [];

        foreach ($this->facets->categories() as $category) {
            $count = $counts[(int) $category->getKey()] ?? 0;

            if ($count === 0) {
                continue;
            }

            $categories[] = [
                'name' => $category->name,
                'url' => route('events.category', ['category' => $category->slug]),
                'count' => $count,
            ];
        }

        usort($categories, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $tags = [];

        foreach ($this->facets->tags() as $tag) {
            $tags[] = [
                'name' => $tag->name,
                'url' => route('events.tag', ['tag' => $tag->slug]),
            ];
        }

        return [
            'categories' => array_slice($categories, 0, self::SUGGESTED_CATEGORIES),
            'tags' => array_slice($tags, 0, self::TAGS),
        ];
    }

    private function meta(City $city, string $term, int $found): PageMeta
    {
        $heading = $term === ''
            ? __('search.title')
            : __('search.results_for', ['query' => $term]);

        return new PageMeta(
            title: __('search.meta.title', ['city' => $city->name]),
            heading: $heading,
            description: $term === ''
                ? __('search.meta.description', ['city' => $city->name])
                : trans_choice('search.meta.results_description', $found, ['count' => $found, 'query' => $term, 'city' => $city->name]),
            canonical: route('search'),
            /* Una ricerca libera non merita una riga in un indice: le stringhe
               sono infinite e nessuna di quelle pagine è un contenuto. */
            indexable: false,
        );
    }
}
