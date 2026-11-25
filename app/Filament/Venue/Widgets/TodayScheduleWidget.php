<?php

declare(strict_types=1);

namespace App\Filament\Venue\Widgets;

use App\Filament\Venue\Widgets\Support\ManagementContext;
use App\Models\EventOccurrence;
use App\Services\Management\DashboardSummary;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

/**
 * Che cosa succede oggi: una card per serata, con la locandina e quello che
 * serve la sera stessa — la scheda, la modifica, la biglietteria.
 *
 * **Se non c'è niente, il riquadro non esiste** (§8.6): niente contenitore
 * vuoto, niente "nessun evento". Il numero «Oggi» sopra dice già che è zero.
 */
class TodayScheduleWidget extends Widget
{
    protected static ?int $sort = 2;

    /* Piccoli e in cima alla pagina: si disegnano subito, senza una richiesta in più per riquadro. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.venue.widgets.today-schedule';

    public static function canView(): bool
    {
        return ManagementContext::tenant() !== null
            && (ManagementContext::occurrences()?->today()->count() ?? 0) > 0;
    }

    /**
     * @return Collection<int, EventOccurrence>
     */
    public function schedule(): Collection
    {
        $tenant = ManagementContext::tenant();

        return $tenant === null ? new Collection : app(DashboardSummary::class)->today($tenant);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cards(): array
    {
        return $this->schedule()
            ->map(fn (EventOccurrence $date): array => ManagementContext::card($date->event, $date, ['view', 'tickets', 'edit']))
            ->all();
    }
}
