<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\TimeOfDay;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\Venue;
use App\Queries\EventOccurrenceQuery;
use App\Services\Seo\StructuredData;
use App\Support\CurrentCity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;

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
    public function __construct(private readonly StructuredData $structuredData) {}

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

        return view('home', [
            'city' => $city,
            'sections' => array_filter($sections, static fn (Collection $section): bool => $section->isNotEmpty()),
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
