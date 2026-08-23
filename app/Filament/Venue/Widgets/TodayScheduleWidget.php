<?php

declare(strict_types=1);

namespace App\Filament\Venue\Widgets;

use App\Filament\Venue\Support\CurrentVenue;
use App\Models\EventOccurrence;
use App\Queries\VenueDashboardQuery;
use App\Support\DateFormatter;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

/**
 * Che cosa succede stasera, qui dentro.
 *
 * **Se non c'è niente, il riquadro non esiste** (§8.6): niente contenitore
 * vuoto, niente "nessun evento". I tre numeri sopra dicono già che oggi è
 * zero; ripeterlo con una tabella vuota sarebbe rumore.
 */
class TodayScheduleWidget extends Widget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.venue.widgets.today-schedule';

    public static function canView(): bool
    {
        return Filament::getTenant() !== null
            && VenueDashboardQuery::for(CurrentVenue::get())->today() > 0;
    }

    /**
     * @return Collection<int, EventOccurrence>
     */
    public function schedule(): Collection
    {
        return VenueDashboardQuery::for(CurrentVenue::get())->todaySchedule();
    }

    public function formatter(): DateFormatter
    {
        return DateFormatter::forTimezone(CurrentVenue::timezone());
    }
}
