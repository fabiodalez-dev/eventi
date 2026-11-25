<?php

declare(strict_types=1);

namespace App\Filament\Venue\Widgets;

use App\Filament\Venue\Widgets\Support\ManagementContext;
use App\Models\EventOccurrence;
use App\Services\Management\DashboardSummary;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

/**
 * I prossimi eventi in cartellone (oggi escluso: ha il suo riquadro), uno per
 * evento, con la locandina e i collegamenti alle pagine che servono prima di
 * una serata: la scheda, la modifica, le grafiche social, la sponsorizzazione.
 *
 * Se non c'è niente in arrivo il riquadro resta, con l'invito a creare un
 * evento (il pulsante è nel riquadro del cartellone, subito sopra): è l'unico
 * caso in cui un riquadro vuoto dice qualcosa da fare.
 */
class UpcomingEventsWidget extends Widget
{
    public const LIMIT = DashboardSummary::UPCOMING_LIMIT;

    protected static ?int $sort = 3;

    /* Piccoli e in cima alla pagina: si disegnano subito, senza una richiesta in più per riquadro. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.venue.widgets.upcoming-events';

    public static function canView(): bool
    {
        return ManagementContext::tenant() !== null;
    }

    /**
     * @return Collection<int, EventOccurrence>
     */
    public function upcoming(): Collection
    {
        $tenant = ManagementContext::tenant();

        return $tenant === null ? new Collection : app(DashboardSummary::class)->upcoming($tenant);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cards(): array
    {
        return $this->upcoming()
            ->map(fn (EventOccurrence $date): array => ManagementContext::card($date->event, $date, ['view', 'edit', 'social', 'sponsor']))
            ->all();
    }

    public function listUrl(): ?string
    {
        $resource = ManagementContext::eventResource();

        return $resource::canViewAny() ? $resource::getUrl('index', ['tab' => 'upcoming']) : null;
    }
}
