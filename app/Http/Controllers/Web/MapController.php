<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\PageMeta;
use App\Enums\DatePreset;
use App\Enums\SponsorshipPlacement;
use App\Enums\VenueStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\MapBoundsRequest;
use App\Models\City;
use App\Models\Sponsorship;
use App\Models\Venue;
use App\Services\Map\MapPayload;
use App\Services\Search\ContextualFacets;
use App\Services\Search\EventFinder;
use App\Services\Search\FilterFacets;
use App\Services\Sponsorship\SponsorshipSelector;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * La mappa degli eventi (§11.6).
 *
 * Tre indirizzi per tre compiti diversi, e nessuno fa il lavoro dell'altro:
 *
 * - `/mappa` è una **pagina**, con gli stessi filtri della lista e un elenco
 *   dei risultati che si legge anche senza JavaScript;
 * - `/mappa/marcatori` è il **carico** che la mappa rilegge a ogni "cerca in
 *   quest'area": rettangolo inquadrato più filtri, punti e nient'altro;
 * - `/mappa/locale/{venue}` è la **card** che appare nel foglio inferiore
 *   quando si tocca un marcatore, disegnata dal server con lo stesso
 *   componente del resto del sito.
 *
 * Le date le sceglie sempre `EventOccurrenceQuery` attraverso `EventFinder`:
 * qui si aggiunge soltanto il rettangolo.
 */
final class MapController extends Controller
{
    use InteractsWithCity;

    /**
     * Quante date di uno stesso locale entrano nel foglio inferiore.
     */
    private const SHEET_SIZE = 6;

    /**
     * Quante card si disegnano nella pagina per chi non ha JavaScript.
     */
    private const FALLBACK_SIZE = 12;

    public function __construct(
        private readonly MapPayload $payload,
        private readonly EventFinder $finder,
        private readonly FilterFacets $facets,
        private readonly SponsorshipSelector $sponsorships,
    ) {}

    public function index(MapBoundsRequest $request): View
    {
        $city = $this->city();
        $filters = $request->filters();

        /* La mappa è uno strumento per decidere cosa fare adesso: alla prima
           apertura mostra oggi. "Tutte le date" è esplicito e resta tale
           mentre si aggiungono categoria, prezzo o luogo. */
        if (! $request->boolean('all_dates') && ! $filters->hasDateWindow()) {
            $filters = $filters->withPreset(DatePreset::Today);
        }

        return view('map.index', [
            'city' => $city,
            'filters' => $filters,
            'facetCounts' => app(ContextualFacets::class)->build($city, $filters),
            'payload' => $this->payload->build($city, $filters, $request->bounds()),
            'occurrences' => $this->finder->take($city, $filters, self::FALLBACK_SIZE),
            'categories' => $this->facets->categories(),
            'tags' => $this->facets->tags(),
            'municipalities' => $this->facets->municipalities($city),
            'zones' => $this->facets->zones($city),
            'venues' => $this->facets->venues($city),
            'meta' => new PageMeta(
                title: __('map.meta.title', ['city' => $city->name]),
                heading: __('map.title'),
                description: __('map.meta.description', ['city' => $city->name]),
                canonical: route('map.index'),
            ),
        ]);
    }

    /**
     * I punti dell'inquadratura corrente.
     *
     * La risposta non va in alcuna cache condivisa: dipende dal rettangolo, e
     * i rettangoli sono infiniti. Vale però la pena che il browser non
     * richieda due volte lo stesso spostamento avanti e indietro.
     */
    public function markers(MapBoundsRequest $request): JsonResponse
    {
        $city = $this->city();

        return response()
            ->json($this->payload->build($city, $request->filters(), $request->bounds()))
            ->header('Cache-Control', $request->user() ? 'private, no-store' : 'private, max-age=60');
    }

    /**
     * Il contenuto del foglio inferiore: le date di **quel** locale che
     * rispondono ai filtri accesi.
     *
     * Torna HTML e non JSON perché ciò che deve comparire è la card evento di
     * §11.4, e quella card esiste già come componente Blade: rifarla in
     * JavaScript significherebbe mantenerne due, e vederle divergere.
     */
    public function venue(MapBoundsRequest $request, int $venue): View
    {
        $city = $this->city();
        $model = $this->findVisible($city, $venue);

        $filters = $request->filters()->withVenue($model->slug);

        return view('map.sheet', [
            'venue' => $model,
            'occurrences' => $this->finder->take($city, $filters, self::SHEET_SIZE),
            'filters' => $filters,
            /* La campagna del foglio compare solo se e' su un evento DI QUESTO
               locale: una pubblicita' che si apre toccando un punto e parla
               d'altro non e' pubblicita', e' un errore. */
            'sponsorship' => $this->sponsorships
                ->forPlacement($city, SponsorshipPlacement::MapSheet)
                ->first(fn (Sponsorship $campagna): bool => $campagna->event?->venue_id === $model->getKey()),
        ]);
    }

    private function findVisible(City $city, int $id): Venue
    {
        $venue = Venue::query()
            ->inCity($city)
            ->whereKey($id)
            ->whereIn('status', [VenueStatus::Approved, VenueStatus::Suspended])
            ->first();

        abort_if($venue === null, 404);

        return $venue;
    }
}
