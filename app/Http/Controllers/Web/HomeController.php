<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\EventFilters;
use App\Enums\EventStatus;
use App\Enums\SponsorshipPlacement;
use App\Enums\TimeOfDay;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CachePage;
use App\Http\Requests\HomeRequest;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Services\Calendar\MonthCalendar;
use App\Services\Map\MapPayload;
use App\Services\RememberedLocation;
use App\Services\Seo\StructuredData;
use App\Services\Sponsorship\SponsorshipSelector;
use App\Services\Sponsorship\SponsorSlots;
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
    /** Fetch a bounded surplus before deduplicating recurring events. */
    private const OVERSAMPLE = 6;

    public function __construct(
        private readonly StructuredData $structuredData,
        private readonly MapPayload $mapPayload,
        private readonly SponsorshipSelector $sponsorships,
        private readonly MonthCalendar $calendar,
        private readonly SponsorSlots $slots,
    ) {}

    public function __invoke(CurrentCity $currentCity, HomeRequest $request, RememberedLocation $locations): View
    {
        $city = $currentCity->get();
        $position = $locations->resolve($request);
        $nearbyRadius = (int) $request->validated('nearby_radius', 5);

        if ($city === null) {
            return view('home', [
                'city' => null,
                'sections' => [],
                'calendar' => null,
                'categories' => [],
                'hero' => null,
                'nearby' => new Collection,
                'stats' => ['upcoming' => 0],
                'statCells' => [],
                'quickFilters' => [],
                'todayLine' => '',
                'heroSponsorship' => null,
                'cardSponsorship' => null,
                'cardSlot' => null,
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
            'tonight' => $this->hydrate(EventOccurrenceQuery::for($city)->tonight()->take($perSection), $perSection),
            'today' => $this->hydrate(EventOccurrenceQuery::for($city)->today()->timeOfDay(TimeOfDay::Day)->take($perSection), $perSection),
            'featured' => $this->hydrate(EventOccurrenceQuery::for($city)->upcoming()->featured()->orderByRelevance()->take($perSection * self::OVERSAMPLE)->unique('event_id')->values(), $perSection),
            'weekend' => $this->hydrate(EventOccurrenceQuery::for($city)->weekend()->take($perSection), $perSection),
        ];

        /* L'apertura resta dentro lo scheletro della pagina, perché da lei
           dipendono il preload e le sezioni qui sotto: la sceglie il piano con
           un caso uguale per tutti dentro la casella della cache. */
        $heroSponsorship = $this->sponsorships->first($city, SponsorshipPlacement::HomeHero);
        $storing = $request->attributes->get(CachePage::STORING) === true;
        $hero = $heroSponsorship !== null
            ? EventOccurrenceQuery::for($city)->forEvent($heroSponsorship->event_id)->promotable()->take(1)->first()
            : EventOccurrenceQuery::for($city)->today()->promotable()->take($perSection * self::OVERSAMPLE)->unique('event_id')->shuffle()->first();
        $hero ??= EventOccurrenceQuery::for($city)->upcoming()->promotable()->take(1)->first();
        $hero?->loadMissing(['event.venue', 'event.category', 'event.media']);

        /*
         * La data in apertura **comprata** non si ripete nelle sezioni.
         *
         * L'apertura pesca dalle stesse finestre temporali delle sezioni sotto,
         * quindi la stessa serata compariva due volte nella prima schermata:
         * grande in cima e di nuovo come card in «Stasera». Quando quel posto è
         * stato venduto la ripetizione ha un costo vero — l'inserzionista paga
         * uno spazio che duplica una riga che avrebbe avuto gratis — e vale la
         * pena toglierla.
         *
         * **Solo in quel caso.** Con un'apertura scelta dalla redazione la
         * ripetizione non costa niente a nessuno, e toglierla farebbe danno
         * proprio dove il catalogo è magro: in una città con un evento solo
         * quella sezione resterebbe vuota e sparirebbe (§8.6), lasciando una
         * prima schermata più povera di prima per risolvere un problema che lì
         * non esiste.
         *
         * Si toglie la singola occorrenza e non l'evento: le altre date dello
         * stesso evento sono serate diverse e restano.
         *
         * Va fatto **prima** di `$visibili` e di `firstVisible()`, che scelgono
         * quali sezioni disegnare e quale locandina annunciare in preload: la
         * seconda deve indicare un'immagine che si vedrà davvero.
         */
        if ($hero !== null && $heroSponsorship !== null) {
            $sections = array_map(
                static fn (Collection $section): Collection => $section
                    ->reject(static fn (EventOccurrence $occurrence): bool => $occurrence->getKey() === $hero->getKey())
                    ->values(),
                $sections,
            );
        }

        $visibili = array_filter($sections, static fn (Collection $section): bool => $section->isNotEmpty());

        return view('home', [
            'city' => $city,
            'sections' => $visibili,
            'lcpOccurrence' => $this->firstVisible($city, $sections),
            // Sponsorizzazione attiva, oppure un evento casuale di oggi.
            'hero' => $hero,
            'nearby' => $this->nearby($city, $position, $nearbyRadius),
            'nearbyRadius' => $nearbyRadius,
            'nearbyPosition' => $position,
            /* La mappa della sezione «vicino a te» mostra tutto ciò che è in
               programma, senza filtri: è una vista d'insieme della città, e
               chi vuole stringere ha la pagina della mappa a un tocco. */
            /* Le due collocazioni della pagina iniziale. Restano `null`
               finche' nessuno ha comprato niente, e la pagina non se ne
               accorge. */
            'heroSponsorship' => $heroSponsorship,
            /* La card, per una pagina che sta per finire in cache, è un buco:
               `CachePage` la sceglie a ogni visita dal piano di consegna. Per
               chi è collegato si sceglie qui, con le sue preferenze. */
            'cardSlot' => $storing ? $this->slots->prepare($city, SponsorshipPlacement::HomeCard) : null,
            'cardSponsorship' => $storing ? null : $this->sponsorships->first($city, SponsorshipPlacement::HomeCard),
            'mapFilters' => $mapFilters = new EventFilters(lat: (float) ($position['lat'] ?? $city->center_lat), lng: (float) ($position['lng'] ?? $city->center_lng), radius: $nearbyRadius),
            'mapPayload' => $this->mapPayload->build($city, $mapFilters, null),
            'stats' => $stats = $this->stats($city),
            'statCells' => $this->statCells($stats),
            'quickFilters' => $this->quickFilters($city),
            'todayLine' => $this->todayLine($city, $stats),
            'calendar' => $this->calendar($city, ($viewer = $request->user()) instanceof User ? $viewer : null),
            'categories' => $this->categories($city),
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
        $live = EventOccurrenceQuery::for($city)->ongoing()->take(1)->first()
            ?? EventOccurrenceQuery::for($city)->startingSoon()->take(1)->first();

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
    /**
     * @param  array{lat: float, lng: float, saved_at: int, expires_at: int}|null  $position
     * @return Collection<int, EventOccurrence>
     */
    private function nearby(City $city, ?array $position = null, int $radius = 5): Collection
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
                ->near((float) ($position['lat'] ?? $city->center_lat), (float) ($position['lng'] ?? $city->center_lng), $radius)
                ->orderByDistance()
                ->take(5 * self::OVERSAMPLE)
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
     * Il calendario del mese, subito sotto l'apertura.
     *
     * Per chi non è collegato è il calendario generale, lo stesso di
     * `/calendario` e in cache insieme alla pagina. Per chi è collegato segue
     * i suoi interessi (categorie scelte, locali, organizzatori, categorie e
     * tag seguiti) e toglie ciò che ha nascosto: la pagina di una persona
     * collegata non entra mai nella cache di pagina (`CachePage`), quindi il
     * suo calendario non può finire a nessun altro.
     *
     * Quando i suoi interessi questo mese non portano a niente, la sezione
     * mostra il calendario della città e lo dice: una griglia vuota direbbe
     * che in città non succede nulla, che è falso. Senza alcuna data nel mese
     * la sezione non si disegna (§8.6).
     *
     * @return array{cells: list<array{date: CarbonImmutable, in_month: bool, is_today: bool, is_past: bool, count: int, titles: list<string>}>, label: string, month: string, total: int, mode: 'city'|'personal'|'fallback'|'filtered'}|null
     */
    private function calendar(City $city, ?User $user): ?array
    {
        $month = CarbonImmutable::now($city->timezone)->startOfMonth();
        $mode = 'city';

        if ($user === null) {
            $cells = $this->calendar->grid($city, $month);
        } else {
            $cells = $this->calendar->personalGrid($city, $month, $user);
            $mode = 'personal';

            if ($cells === null || array_sum(array_column($cells, 'count')) === 0) {
                $mode = $cells === null ? 'filtered' : 'fallback';
                $cells = $this->calendar->cells($city, $month, $this->calendar->filteredDigest($city, $month, $user));
            }
        }

        $total = array_sum(array_column($cells, 'count'));

        if ($total === 0) {
            return null;
        }

        return [
            'cells' => $cells,
            'label' => __('calendar.month_year', [
                'month' => DateFormatter::for($city)->monthName($month),
                'year' => $month->format('Y'),
            ]),
            'month' => $month->format('Y-m'),
            'total' => $total,
            'mode' => $mode,
        ];
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
