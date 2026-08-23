<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\EventOccurrence;
use App\Queries\EventOccurrenceQuery;
use App\Support\CurrentCity;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * "In corso adesso" e "Inizia tra poco" (§11.2, punti 2 e 3).
 *
 * Sono le sole due sezioni la cui risposta cambia da un minuto all'altro, e
 * per questo vivono fuori dalla pagina: §12.3 chiede uno scheletro di homepage
 * in cache per cinque minuti e queste due finestre in un frammento a parte,
 * caricato **dopo** il primo disegno. Se stessero nella stessa pagina, o la
 * pagina sarebbe in cache e le sezioni mentirebbero, o le sezioni sarebbero
 * giuste e la pagina non si potrebbe mettere in cache.
 *
 * Le due finestre restano definite in `EventOccurrenceQuery` (§8): qui si
 * chiedono, non si ricalcolano.
 */
#[Lazy]
class LiveNow extends Component
{
    /**
     * Quante card per sezione: chi guarda "adesso" vuole scegliere, non
     * sfogliare un catalogo.
     */
    private const PER_SECTION = 6;

    public function render(): View
    {
        $city = app(CurrentCity::class)->get();

        $ongoing = $city === null ? new Collection : $this->hydrate(EventOccurrenceQuery::for($city)->ongoing()->get());
        $startingSoon = $city === null ? new Collection : $this->hydrate(EventOccurrenceQuery::for($city)->startingSoon()->get());

        return view('livewire.live-now', [
            'ongoing' => $ongoing,
            'startingSoon' => $startingSoon,
        ]);
    }

    /**
     * Lo scheletro mostrato prima che il frammento arrivi. Non promette card
     * che potrebbero non esserci: dice soltanto che sta guardando.
     */
    public function placeholder(): View
    {
        return view('livewire.live-now-placeholder');
    }

    /**
     * @param  Collection<int, EventOccurrence>  $occurrences
     * @return Collection<int, EventOccurrence>
     */
    private function hydrate(Collection $occurrences): Collection
    {
        /** @var Collection<int, EventOccurrence> $section */
        $section = $occurrences->take(self::PER_SECTION);

        return $section->load(['event.venue', 'event.category', 'event.media']);
    }
}
