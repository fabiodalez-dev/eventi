<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Models\City;
use App\Services\Calendar\MonthCalendar;
use App\Support\DateFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

/**
 * Il calendario mensile (§11.8).
 *
 * La griglia è scritta a mano in Blade: quarantadue caselle e sei righe non
 * valgono le centinaia di chilobyte di una libreria di calendari, che
 * porterebbe con sé un proprio modello di dati, un proprio fuso orario e una
 * propria idea di che cosa sia "oggi" — cioè esattamente ciò che §8 vuole che
 * esista in un posto solo.
 *
 * I conteggi arrivano da `MonthCalendar`, che ne fa **una sola** query
 * aggregata per mese e la tiene in cache (§11.8, §12.3).
 */
final class CalendarController extends Controller
{
    use InteractsWithCity;

    /**
     * Quanto lontano si può andare avanti e indietro. Non è una prudenza
     * eccessiva: senza un limite, i mesi sono infiniti e un motore di ricerca
     * li percorrerebbe tutti, uno alla volta, per sempre.
     */
    private const YEARS_AROUND = 3;

    public function __construct(private readonly MonthCalendar $calendar) {}

    public function __invoke(?string $month = null): View
    {
        $city = $this->city();
        $current = $this->month($city, $month);

        $formatter = DateFormatter::for($city);
        $label = __('calendar.month_year', [
            'month' => $formatter->monthName($current),
            'year' => $current->format('Y'),
        ]);

        $cells = $this->calendar->grid($city, $current);
        $total = array_sum(array_column($cells, 'count'));

        return view('calendar.index', [
            'city' => $city,
            'month' => $current,
            'label' => $label,
            'cells' => $cells,
            'total' => $total,
            'previous' => $this->neighbour($city, $current->subMonth()),
            'next' => $this->neighbour($city, $current->addMonth()),
            'today' => CarbonImmutable::now($city->timezone)->startOfDay(),
            'meta' => new PageMeta(
                title: __('calendar.meta.title', ['month' => $label, 'city' => $city->name]),
                heading: __('calendar.title'),
                description: __('calendar.meta.description', ['month' => $label, 'city' => $city->name]),
                canonical: route('calendar.month', ['month' => $current->format('Y-m')]),
                /* Un mese senza date è una pagina senza contenuto: resta
                   raggiungibile e navigabile, ma non chiede di essere indicizzata. */
                indexable: $total > 0,
            ),
        ]);
    }

    /**
     * Il mese chiesto, oppure quello corrente.
     */
    private function month(City $city, ?string $month): CarbonImmutable
    {
        $now = CarbonImmutable::now($city->timezone)->startOfMonth()->startOfDay();

        if ($month === null) {
            return $now;
        }

        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month) === 1, 404);

        [$year, $number] = array_map(intval(...), explode('-', $month));

        abort_unless($number >= 1 && $number <= 12, 404);

        $requested = CarbonImmutable::create($year, $number, 1, 0, 0, 0, $city->timezone);

        abort_unless($this->isWithinRange($now, $requested), 404);

        return $requested;
    }

    /**
     * L'indirizzo del mese precedente o seguente, `null` se cade fuori
     * dall'intervallo percorribile.
     */
    private function neighbour(City $city, CarbonImmutable $month): ?string
    {
        $now = CarbonImmutable::now($city->timezone)->startOfMonth()->startOfDay();

        if (! $this->isWithinRange($now, $month)) {
            return null;
        }

        return route('calendar.month', ['month' => $month->format('Y-m')]);
    }

    private function isWithinRange(CarbonImmutable $now, CarbonImmutable $month): bool
    {
        return $month >= $now->subYears(self::YEARS_AROUND) && $month <= $now->addYears(self::YEARS_AROUND);
    }
}
