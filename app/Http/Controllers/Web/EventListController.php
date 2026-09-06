<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\EventFilters;
use App\Enums\DatePreset;
use App\Enums\PriceFilter;
use App\Enums\SponsorshipPlacement;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\EventFilterRequest;
use App\Models\Category;
use App\Models\Tag;
use App\Services\Map\MapPayload;
use App\Services\Search\EventFinder;
use App\Services\Search\FilterFacets;
use App\Services\Seo\EventListingMeta;
use App\Services\Seo\StructuredData;
use App\Services\Sponsorship\SponsorshipSelector;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

/**
 * La lista degli eventi e tutte le sue scorciatoie (§11.3).
 *
 * Le rotte parlanti — `/eventi/oggi`, `/eventi/gratis`,
 * `/eventi/categoria/{slug}` — non sono pagine diverse: sono la stessa lista
 * con un filtro già acceso. Da lì in poi ogni interazione scrive il filtro
 * nella query string, perché l'URL deve poter essere copiato, condiviso e
 * riaperto identico (§11.3).
 */
final class EventListController extends Controller
{
    use InteractsWithCity;

    public function __construct(
        private readonly EventFinder $finder,
        private readonly EventListingMeta $meta,
        private readonly FilterFacets $facets,
        private readonly StructuredData $structuredData,
        private readonly MapPayload $mapPayload,
        private readonly SponsorshipSelector $sponsorships,
    ) {}

    public function index(EventFilterRequest $request): View
    {
        return $this->render($request->filters());
    }

    public function today(EventFilterRequest $request): View
    {
        return $this->render($request->filters()->withPreset(DatePreset::Today));
    }

    public function tomorrow(EventFilterRequest $request): View
    {
        return $this->render($request->filters()->withPreset(DatePreset::Tomorrow));
    }

    public function weekend(EventFilterRequest $request): View
    {
        return $this->render($request->filters()->withPreset(DatePreset::Weekend));
    }

    public function free(EventFilterRequest $request): View
    {
        return $this->render($request->filters()->withPrice(PriceFilter::Free));
    }

    /**
     * `/eventi/2026-09-05`. La data è già vincolata dalla rotta al formato
     * `yyyy-mm-dd`: qui resta da verificare che sia un giorno esistente.
     */
    public function onDate(EventFilterRequest $request, string $date): View
    {
        $day = CarbonImmutable::createFromFormat('Y-m-d', $date);

        /* Il 31 febbraio non esiste: Carbon lo trasforma nel 3 marzo, e il
           confronto con ciò che è stato chiesto lo smaschera. */
        abort_if($day->format('Y-m-d') !== $date, 404);

        return $this->render($request->filters()->withDate($day->startOfDay()));
    }

    public function category(EventFilterRequest $request, Category $category): View
    {
        abort_unless($category->is_active, 404);

        return $this->render($request->filters()->withCategories([$category->slug]));
    }

    public function tag(EventFilterRequest $request, Tag $tag): View
    {
        abort_unless($tag->is_approved, 404);

        return $this->render($request->filters()->withTags([$tag->slug]));
    }

    private function render(EventFilters $filters): View
    {
        $city = $this->city();

        $occurrences = $this->finder->paginate($city, $filters);

        $meta = $this->meta
            ->build($city, $filters, $occurrences->total())
            ->withCanonical($this->canonical($filters));

        return view('events.index', [
            'city' => $city,
            'filters' => $filters,
            'occurrences' => $occurrences,
            /* La mappa affiancata all'elenco mostra gli STESSI filtri: e' il
               senso della terza colonna del riferimento (D46) — si stringe un
               filtro a sinistra e i punti a destra si diradano. Il carico e'
               quello della pagina della mappa, calcolato dallo stesso servizio
               perche' due elenchi che dicono cose diverse sono peggio di uno
               solo. */
            'mapPayload' => $this->mapPayload->build($city, $filters, null),
            /* La campagna in cima ai risultati (§sponsorizzazioni). E' `null`
               quasi sempre, e la vista non disegna niente: uno slot vuoto non
               lascia un buco. */
            'sponsorship' => $this->sponsorships->first($city, SponsorshipPlacement::ListTop),
            'meta' => $meta,
            'categories' => $this->facets->categories(),
            'tags' => $this->facets->tags(),
            'municipalities' => $this->facets->municipalities($city),
            'zones' => $this->facets->zones($city),
            'venues' => $this->facets->venues($city),
            'structuredData' => [$this->structuredData->collection($meta->title, $meta->canonical,
                collect($occurrences->items())->map(fn ($occurrence): array => [
                    'name' => $occurrence->event->title, 'url' => route('events.show', $occurrence->event),
                ])->values()->all()), $this->structuredData->breadcrumbs([
                    ['name' => __('ui.nav.home'), 'url' => url('/')],
                    ['name' => $meta->heading, 'url' => url()->current()],
                ])],
        ]);
    }

    /**
     * Lo stesso insieme di filtri ha **un solo** indirizzo canonico, comunque
     * ci si sia arrivati: chi apre `/eventi?date=today` e chi apre
     * `/eventi/oggi` vedono la stessa pagina, e dichiarano lo stesso canonico.
     *
     * Le rotte parlanti vincono, ma solo quando esprimono da sole tutta la
     * richiesta: appena si aggiunge un secondo filtro l'indirizzo torna alla
     * forma con la query string, che è l'unica capace di rappresentarli tutti.
     */
    private function canonical(EventFilters $filters): string
    {
        $parameters = $filters->toQueryString();
        $page = max(1, request()->integer('page', 1));

        if (count($parameters) === 1) {
            $pretty = $this->prettyRoute($filters);

            if ($pretty !== null) {
                return $page > 1 ? $pretty.'?page='.$page : $pretty;
            }
        }

        $parameters = $filters->toQueryString();
        if ($page > 1) {
            $parameters['page'] = $page;
        }

        return $parameters === [] ? route('events.index') : route('events.index').'?'.http_build_query($parameters);
    }

    private function prettyRoute(EventFilters $filters): ?string
    {
        if ($filters->preset !== null) {
            return match ($filters->preset) {
                DatePreset::Today => route('events.today'),
                DatePreset::Tomorrow => route('events.tomorrow'),
                DatePreset::Weekend => route('events.weekend'),
                DatePreset::Tonight, DatePreset::Week, DatePreset::StartingSoon, DatePreset::Ongoing => null,
            };
        }

        if ($filters->price === PriceFilter::Free) {
            return route('events.free');
        }

        if ($filters->date !== null) {
            return route('events.date', ['date' => $filters->date->format('Y-m-d')]);
        }

        if (count($filters->categories) === 1) {
            return route('events.category', ['category' => $filters->categories[0]]);
        }

        if (count($filters->tags) === 1) {
            return route('events.tag', ['tag' => $filters->tags[0]]);
        }

        return null;
    }
}
