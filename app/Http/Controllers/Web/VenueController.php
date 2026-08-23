<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\EventFilters;
use App\DTOs\PageMeta;
use App\Enums\VenueStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\VenueFilterRequest;
use App\Models\City;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Services\Search\FilterFacets;
use App\Services\Seo\StructuredData;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Elenco e scheda dei locali (§11.9).
 *
 * La scheda ha due liste con vite diverse: i prossimi eventi, che è ciò per
 * cui la gente arriva, e l'archivio di quelli passati, che è ciò che rende la
 * pagina interessante per un motore di ricerca. L'archivio ha una paginazione
 * propria (`?archivio=2`), così scorrerlo non fa perdere il resto della pagina.
 */
final class VenueController extends Controller
{
    use InteractsWithCity;

    private const ARCHIVE_PAGE_NAME = 'archivio';

    private const ARCHIVE_PER_PAGE = 12;

    public function __construct(
        private readonly FilterFacets $facets,
        private readonly StructuredData $structuredData,
    ) {}

    public function index(VenueFilterRequest $request): View
    {
        $city = $this->city();
        $type = $request->type();
        $municipality = $request->municipality();
        $term = $request->term();

        $venues = Venue::query()
            ->approved()
            ->inCity($city)
            ->with('media')
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type))
            ->when($municipality !== null, fn (Builder $query) => $query->where('municipality', $municipality))
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $match) use ($term): void {
                $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

                $match->where('name', 'like', $pattern)
                    ->orWhere('short_description', 'like', $pattern)
                    ->orWhere('municipality', 'like', $pattern);
            }))
            ->orderByDesc('is_verified')
            ->orderBy('name')
            ->paginate(config()->integer('eventi.per_page'))
            ->withQueryString();

        return view('venues.index', [
            'city' => $city,
            'venues' => $venues,
            'upcomingCounts' => EventOccurrenceQuery::for($city)->upcoming()->countsByVenue(),
            'municipalities' => $this->facets->municipalities($city),
            'type' => $type,
            'municipality' => $municipality,
            'term' => $term,
            'meta' => new PageMeta(
                title: __('venues.meta.title', ['city' => $city->name]),
                heading: __('venues.title'),
                description: __('venues.meta.description', ['city' => $city->name]),
                canonical: route('venues.index'),
            ),
            'structuredData' => [$this->structuredData->breadcrumbs([
                ['name' => __('ui.nav.home'), 'url' => url('/')],
                ['name' => __('venues.title'), 'url' => route('venues.index')],
            ])],
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $city = $this->city();
        $venue = $this->findVisible($city, $slug);

        $upcoming = EventOccurrenceQuery::for($city)
            ->upcoming()
            ->atVenue($venue)
            ->get()
            ->load(['event.venue', 'event.category', 'event.media']);

        $archive = EventOccurrenceQuery::for($city)
            ->past()
            ->atVenue($venue)
            ->orderByNewestFirst()
            ->paginate(self::ARCHIVE_PER_PAGE, page: $this->archivePage($request), pageName: self::ARCHIVE_PAGE_NAME)
            ->withQueryString();

        (new Collection($archive->items()))->load(['event.venue', 'event.category', 'event.media']);

        return view('venues.show', [
            'city' => $city,
            'venue' => $venue,
            'occurrences' => $upcoming,
            'archive' => $archive,
            /* Il calendario e l'RSS di questo solo locale (§11.10): gli
               stessi filtri della lista, con il locale già scelto. */
            'feedFilters' => new EventFilters(venue: $venue->slug),
            'meta' => $this->meta($venue),
            'structuredData' => [
                $this->structuredData->venue($venue),
                $this->structuredData->breadcrumbs([
                    ['name' => __('ui.nav.home'), 'url' => url('/')],
                    ['name' => __('venues.title'), 'url' => route('venues.index')],
                    ['name' => $venue->name, 'url' => route('venues.show', $venue)],
                ]),
            ],
        ]);
    }

    private function findVisible(City $city, string $slug): Venue
    {
        $venue = Venue::query()
            ->with(['city', 'media'])
            ->inCity($city)
            ->where('slug', $slug)
            ->whereIn('status', [VenueStatus::Approved, VenueStatus::Suspended])
            ->first();

        abort_if($venue === null, 404);

        /*
         * Un locale sospeso resta raggiungibile ma non è pubblicità: la scheda
         * lo dichiara e non entra negli indici.
         */
        return $venue;
    }

    private function meta(Venue $venue): PageMeta
    {
        $description = $venue->short_description
            ?? Str::of((string) $venue->description)->stripTags()->squish()->limit(180)->value();

        return new PageMeta(
            title: __('venues.meta.venue_title', ['venue' => $venue->name, 'municipality' => $venue->municipality]),
            heading: $venue->name,
            description: $description === '' ? null : $description,
            canonical: route('venues.show', $venue),
            image: $venue->getFirstMediaUrl('cover') !== '' ? $venue->getFirstMediaUrl('cover') : null,
            indexable: $venue->status === VenueStatus::Approved,
        );
    }

    private function archivePage(Request $request): int
    {
        return max(1, $request->integer(self::ARCHIVE_PAGE_NAME, 1));
    }
}
