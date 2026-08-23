<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Cache\LiveWindows;
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
 * chiedono, non si ricalcolano. Fra le due c'è `App\Services\Cache\LiveWindows`,
 * che le tiene per sessanta secondi con la chiave arrotondata al quarto d'ora.
 */
#[Lazy]
class LiveNow extends Component
{
    /**
     * Quante card per sezione: chi guarda "adesso" vuole scegliere, non
     * sfogliare un catalogo.
     */
    private const PER_SECTION = 6;

    public function render(LiveWindows $windows): View
    {
        $city = app(CurrentCity::class)->get();

        return view('livewire.live-now', [
            'ongoing' => $city === null ? new Collection : $windows->ongoing($city, self::PER_SECTION),
            'startingSoon' => $city === null ? new Collection : $windows->startingSoon($city, self::PER_SECTION),
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
}
