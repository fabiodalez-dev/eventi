<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Services\Cache\LiveWindows;
use App\Support\CurrentCity;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

/**
 * Il prossimo evento, accanto al calendario della pagina iniziale.
 *
 * Cambia di minuto in minuto come «In corso adesso», quindi vive nello stesso
 * buco della cache di pagina (`CachePage`): la copia salvata non lo contiene e
 * ogni risposta lo ridisegna. La finestra la decide `LiveWindows::next()`,
 * che segue gli interessi di chi è collegato.
 */
final class HomeNextEvent extends Component
{
    public ?EventOccurrence $occurrence = null;

    /** @var Collection<int, EventOccurrence> Le date che seguono la prima. */
    public Collection $following;

    public bool $personal = false;

    /** Comincia entro la soglia «inizia tra poco» della città. */
    public bool $soon = false;

    public function __construct(LiveWindows $windows, CurrentCity $currentCity)
    {
        $this->following = new Collection;
        $city = $currentCity->get();

        if (! $city instanceof City) {
            return;
        }

        $user = Auth::user();
        $next = $windows->next($city, $user instanceof User ? $user : null);

        if ($next === null) {
            return;
        }

        $this->occurrence = $next['occurrences']->first();
        $this->following = $next['occurrences']->slice(1)->values();
        $this->personal = $next['personal'];

        $starts = $this->occurrence->starts_at;
        $now = EventOccurrenceQuery::for($city)->now();
        $this->soon = $starts->lessThanOrEqualTo($now->addMinutes($city->starting_soon_minutes));
    }

    public function shouldRender(): bool
    {
        return $this->occurrence !== null;
    }

    public function render(): View
    {
        return view('components.home-next-event');
    }
}
