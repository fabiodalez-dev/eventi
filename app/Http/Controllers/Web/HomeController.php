<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\EventFilters;
use App\Enums\EventStatus;
use App\Enums\SponsorshipPlacement;
use App\Enums\TimeOfDay;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Services\Map\MapPayload;
use App\Services\Seo\StructuredData;
use App\Services\Sponsorship\SponsorshipSelector;
use App\Support\CurrentCity;
use App\Support\DateFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Pagina iniziale (§11.2). Ogni sezione temporale viene da
 * `EventOccurrenceQuery` e da nessun'altra parte (§8): qui si sceglie soltanto
 * quali finestre mostrare e in quale ordine — un ordine che il piano fissa e
 * che non si cambia per gusto.
 *
 * Le sezioni vuote non arrivano nemmeno alla vista: una finestra senza eventi
 * non si disegna affatto (§8.6).
 *
 * "In corso adesso" e "Inizia tra poco" **non stanno qui**: sono un componente
 * Livewire caricato dopo il primo disegno della pagina. Sono le sole due
 * sezioni la cui risposta cambia ogni minuto, e tenerle fuori dalla pagina è
 * ciò che permetterà di metterla in cache senza mentire (§12.3).
 */
final class HomeController extends Controller
{
    public function __construct(
        private readonly StructuredData $structuredData,
        private readonly MapPayload $mapPayload,
        private readonly SponsorshipSelector $sponsorships,
    ) {}

    public function __invoke(CurrentCity $currentCity): View
    {
        $city = $currentCity->get();

        if ($city === null) {
            return view('home', [
                'city' => null,
                'sections' => [],
                'days' => [],
                'categories' => [],
                'venues' => new Collection,
                'hero' => null,
                'nearby' => new Collection,
                'stats' => ['upcoming' => 0],
                'statCells' => [],
                'quickFilters' => [],
                'todayLine' => '',
                'heroSponsorship' => null,
                'cardSponsorship' => null,
                'mapFilters' => new EventFilters,
                'mapPayload' => ['markers' => [], 'categories' => [], 'truncated' => false],
                'structuredData' => [
                    $this->structuredData->website(null),
                    $this->structuredData->organization(),
                ],
            ]);
        }

        $perSection = config()->integer('eventi.home_section_size');

        $sections = [
            'tonight' => $this->hydrate(EventOccurrenceQuery::for($city)->tonight()->get(), $perSection),
            'today' => $this->hydrate(EventOccurrenceQuery::for($city)->today()->timeOfDay(TimeOfDay::Day)->get(), $perSection),
            'featured' => $this->hydrate(EventOccurrenceQuery::for($city)->upcoming()->featured()->orderByRelevance()->get()->unique('event_id')->values(), $perSection),
            'weekend' => $this->hydrate(EventOccurrenceQuery::for($city)->weekend()->get(), $perSection),
        ];

        $visibili = array_filter($sections, static fn (Collection $section): bool => $section->isNotEmpty());

        $heroSponsorship = $this->sponsorships->first($city, SponsorshipPlacement::HomeHero);
        $hero = $heroSponsorship !== null
            ? EventOccurrenceQuery::for($city)->forEvent($heroSponsorship->event_id)->promotable()->get()->first()
            : EventOccurrenceQuery::for($city)->today()->promotable()->get()->unique('event_id')->shuffle()->first();
        $hero?->loadMissing(['event.venue', 'event.category', 'event.media']);

        return view('home', [
            'city' => $city,
            'sections' => $visibili,
            'lcpOccurrence' => $this->firstVisible($city, $sections),
            // Sponsorizzazione attiva, oppure un evento casuale di oggi.
            'hero' => $hero,
            'nearby' => $this->nearby($city),
            /* La mappa della sezione «vicino a te» mostra tutto ciò che è in
               programma, senza filtri: è una vista d'insieme della città, e
               chi vuole stringere ha la pagina della mappa a un tocco. */
            /* Le due collocazioni della pagina iniziale. Restano `null`
               finche' nessuno ha comprato niente, e la pagina non se ne
               accorge. */
            'heroSponsorship' => $heroSponsorship,
            'cardSponsorship' => $this->sponsorships->first($city, SponsorshipPlacement::HomeCard),
            'mapFilters' => $mapFilters = new EventFilters,
            'mapPayload' => $this->mapPayload->build($city, $mapFilters, null),
            'stats' => $stats = $this->stats($city),
            'statCells' => $this->statCells($stats),
            'quickFilters' => $this->quickFilters($city),
            'todayLine' => $this->todayLine($city, $stats),
            'days' => $this->days($city),
            'categories' => $this->categories($city),
            'venues' => $this->venues($city),
            'structuredData' => [
                $this->structuredData->website($city),
                $this->structuredData->organization(),
            ],
        ]);
    }

    /**
     * L'occorrenza la cui locandina sara la prima immagine grande della pagina.
     *
     * Serve al preload di §11.11, e va calcolata nell'ordine in cui le sezioni
     * COMPAIONO, non in quello in cui il controller le interroga. La differenza
     * conta: "In corso adesso" e "Inizia tra poco" stanno in un componente
     * caricato dopo il primo disegno, ma sono le prime due sezioni della pagina
     * (§11.2) — quindi la loro locandina e l'immagine piu grande sopra la
     * piega, e annunciare quella di "Stasera" faceva scaricare in anticipo
     * un'immagine che l'utente vede solo scorrendo.
     *
     * Le due query aggiuntive costano poco: quello che non si puo mettere nella
     * pagina in cache e il RISULTATO, che cambia ogni minuto (§12.3), non la
     * domanda.
     *
     * @param  array<string, Collection<int, EventOccurrence>>  $sections
     */
    private function firstVisible(City $city, array $sections): ?EventOccurrence
    {
        $live = EventOccurrenceQuery::for($city)->ongoing()->get()->first()
            ?? EventOccurrenceQuery::for($city)->startingSoon()->get()->first();

        if ($live !== null) {
            return $this->hydrate(new Collection([$live]), 1)->first();
        }

        foreach (['tonight', 'today', 'featured', 'weekend'] as $key) {
            if (($sections[$key] ?? null)?->isNotEmpty() === true) {
                return $sections[$key]->first();
            }
        }

        return null;
    }

    /**
     * Le date piu' vicine al centro citta', per la sezione «vicino a te».
     *
     * **La posizione non si chiede all'apertura** (§11.7): finche' nessuno
     * tocca il pulsante di localizzazione dentro la mappa, «vicino» vuol dire
     * vicino al centro. E' una scelta onesta e non un ripiego — chi apre il
     * sito da casa vuole vedere cosa succede in citta', non cosa succede sotto
     * al proprio balcone.
     *
     * @return Collection<int, EventOccurrence>
     */
    private function nearby(City $city): Collection
    {
        /*
         * Le colonne si chiamano `center_lat` e `center_lng`, e sono
         * obbligatorie: una citta' senza centro non esiste, quindi qui non c'e'
         * niente da controllare.
         *
         * La prima versione leggeva `latitude` e `longitude`, che su questo
         * modello non esistono. Su un modello Eloquent una proprieta'
         * inesistente vale `null` e non solleva niente: la guardia scattava
         * sempre, il metodo restituiva sempre una collezione vuota, e la
         * sezione «vicino a te» non compariva mai. Nessun errore, nessun log,
         * nessun indizio — l'ha trovata l'analisi statica, non la pagina.
         */
        return $this->hydrate(
            EventOccurrenceQuery::for($city)
                ->upcoming()
                ->near((float) $city->center_lat, (float) $city->center_lng, config()->float('eventi.nearby_radius_km', 12.0))
                ->orderByDistance()
                ->get()
                ->unique('event_id')
                ->values(),
            5,
        );
    }

    /**
     * Le misure di §1: non «quanti utenti abbiamo», ma «c'e' qualcosa da
     * fare?». Sono i numeri della fascia sotto l'apertura.
     *
     * @return array{upcoming: int, week: int, venues: int, categories: int, updated: ?CarbonImmutable}
     */
    private function stats(City $city): array
    {
        $ultimo = Event::query()
            ->where('city_id', $city->getKey())
            ->where('status', EventStatus::Published)
            ->max('updated_at');

        return [
            'upcoming' => EventOccurrenceQuery::for($city)->upcoming()->count(),
            'week' => EventOccurrenceQuery::for($city)->nextDays(7)->count(),
            'venues' => Venue::query()->inCity($city)->approved()->count(),
            'categories' => count(EventOccurrenceQuery::for($city)->upcoming()->countsByCategory()),
            /* «Aggiornato N minuti fa» e' una promessa verificabile: viene
               dall'ultima modifica vera in catalogo, non da `now()`. Se il
               catalogo e' fermo da due giorni, lo dice. */
            'updated' => is_string($ultimo)
                ? CarbonImmutable::parse($ultimo, 'UTC')->setTimezone($city->timezone)
                : null,
        ];
    }

    /**
     * @param  array{upcoming: int, week: int, venues: int, categories: int, updated: ?CarbonImmutable}  $stats
     * @return list<array{value: string, label: string, accent?: bool}>
     */
    private function statCells(array $stats): array
    {
        $celle = [
            ['value' => (string) $stats['week'], 'label' => __('ui.stats.week')],
            ['value' => (string) $stats['venues'], 'label' => __('ui.stats.venues')],
            ['value' => (string) $stats['categories'], 'label' => __('ui.stats.categories')],
        ];

        if ($stats['updated'] instanceof CarbonImmutable) {
            $celle[] = [
                'value' => $stats['updated']->diffForHumans(syntax: CarbonImmutable::DIFF_ABSOLUTE, short: true),
                'label' => __('ui.stats.updated'),
                'accent' => true,
            ];
        }

        return $celle;
    }

    /**
     * I quattro ritagli rapidi sotto il titolo di apertura, col conteggio.
     *
     * Uno con zero date non compare: un pulsante «Stasera 0» invita a un
     * elenco vuoto, ed e' il modo piu' rapido per far credere che il sito non
     * abbia niente (§8.6).
     *
     * @return list<array{label: string, url: string, count: int}>
     */
    private function quickFilters(City $city): array
    {
        $ritagli = [
            ['label' => __('ui.nav.today'), 'route' => 'events.today', 'query' => EventOccurrenceQuery::for($city)->today()],
            ['label' => __('ui.nav.tomorrow'), 'route' => 'events.tomorrow', 'query' => EventOccurrenceQuery::for($city)->tomorrow()],
            ['label' => __('ui.nav.weekend'), 'route' => 'events.weekend', 'query' => EventOccurrenceQuery::for($city)->weekend()],
            ['label' => __('ui.nav.free'), 'route' => 'events.free', 'query' => EventOccurrenceQuery::for($city)->upcoming()->priceFree()],
        ];

        $disponibili = [];

        foreach ($ritagli as $ritaglio) {
            if (! Route::has($ritaglio['route'])) {
                continue;
            }

            $quante = $ritaglio['query']->count();

            if ($quante === 0) {
                continue;
            }

            $disponibili[] = [
                'label' => $ritaglio['label'],
                'url' => route($ritaglio['route']),
                'count' => $quante,
            ];
        }

        return $disponibili;
    }

    /**
     * La riga sopra il titolo: data di oggi, quante date ci sono, da quanto e'
     * aggiornato il catalogo.
     *
     * @param  array{upcoming: int, week: int, venues: int, categories: int, updated: ?CarbonImmutable}  $stats
     */
    private function todayLine(City $city, array $stats): string
    {
        $formatter = app(DateFormatter::class);

        $pezzi = [
            $formatter->weekdayDate(CarbonImmutable::now($city->timezone)),
            trans_choice('ui.stats.in_town', $stats['upcoming'], ['count' => $stats['upcoming']]),
        ];

        if ($stats['updated'] instanceof CarbonImmutable) {
            $pezzi[] = __('ui.stats.updated_ago', ['ago' => $stats['updated']->diffForHumans()]);
        }

        return implode(' '.__('common.separator').' ', $pezzi);
    }

    /**
     * Lo scroller dei prossimi giorni con l'indicatore di densità (§11.2).
     *
     * I conteggi arrivano da una sola interrogazione aggregata, e i giorni
     * senza eventi restano nell'elenco con densità zero: qui la fila di giorni
     * è un calendario, e un calendario con i buchi tolti non si legge più.
     *
     * @return list<array{date: CarbonImmutable, count: int, density: int}>
     */
    private function days(City $city): array
    {
        $span = config()->integer('eventi.day_scroller_days');
        $counts = EventOccurrenceQuery::for($city)->nextDays($span)->countsByBusinessDate();

        if ($counts === []) {
            return [];
        }

        $busiest = max($counts);
        $today = CarbonImmutable::now($city->timezone)->startOfDay();
        $days = [];

        foreach (range(0, $span - 1) as $offset) {
            $date = $today->addDays($offset);
            $count = $counts[$date->format('Y-m-d')] ?? 0;

            $days[] = [
                'date' => $date,
                'count' => $count,
                'density' => $busiest === 0 ? 0 : (int) ceil($count / $busiest * 3),
            ];
        }

        return $days;
    }

    /**
     * La griglia per categoria mostra le sole categorie che hanno davvero
     * qualcosa in programma: una casella che porta a una lista vuota è un
     * contenitore vuoto con un passaggio in più (§8.6).
     *
     * @return list<array{category: Category, count: int}>
     */
    private function categories(City $city): array
    {
        $counts = EventOccurrenceQuery::for($city)->upcoming()->countsByCategory();

        if ($counts === []) {
            return [];
        }

        $categories = Category::query()
            ->active()
            ->ordered()
            ->whereKey(array_keys($counts))
            ->get();

        $grid = [];

        foreach ($categories as $category) {
            $grid[] = ['category' => $category, 'count' => $counts[(int) $category->getKey()] ?? 0];
        }

        return $grid;
    }

    /**
     * I locali attivi sono quelli che hanno davvero date in programma, in
     * ordine di quante ne hanno: "attivo" è una constatazione, non un premio.
     *
     * @return Collection<int, Venue>
     */
    private function venues(City $city): Collection
    {
        $counts = EventOccurrenceQuery::for($city)->upcoming()->countsByVenue();

        if ($counts === []) {
            return new Collection;
        }

        arsort($counts);
        $ids = array_slice(array_keys($counts), 0, 4);

        return Venue::query()
            ->approved()
            ->inCity($city)
            ->whereKey($ids)
            ->with('media')
            ->get()
            ->sortBy(static fn (Venue $venue): int => array_search((int) $venue->getKey(), $ids, true) ?: 0)
            ->values();
    }

    /**
     * Taglia la sezione e carica in un colpo solo ciò che la card legge.
     *
     * @param  Collection<int, EventOccurrence>  $occurrences
     * @return Collection<int, EventOccurrence>
     */
    private function hydrate(Collection $occurrences, int $limit): Collection
    {
        /** @var Collection<int, EventOccurrence> $section */
        $section = $occurrences->take($limit);

        return $section->load(['event.venue', 'event.category', 'event.media']);
    }
}
