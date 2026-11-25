<?php

declare(strict_types=1);

namespace App\Filament\Venue\Widgets;

use App\Filament\Venue\Widgets\Support\ManagementContext;
use App\Models\Event;
use App\Services\Management\DashboardSummary;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * «Ultime visite»: le tre schede più aperte negli ultimi sette giorni, con
 * la locandina e il numero di visite, e il collegamento alle statistiche
 * complete. Le visite sono quelle aggregate per giorno (`event_views_daily`),
 * contate con il consenso alle statistiche come nel resto del pannello.
 *
 * Senza visite nel periodo il riquadro non c'è (§8.6): il riepilogo del
 * periodo, sotto, lo dice già con il suo stato vuoto.
 */
class RecentViewsWidget extends Widget
{
    public const DAYS = DashboardSummary::RECENT_DAYS;

    protected static ?int $sort = 4;

    /* Piccoli e in cima alla pagina: si disegnano subito, senza una richiesta in più per riquadro. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.venue.widgets.recent-views';

    public static function canView(): bool
    {
        return ManagementContext::tenant() !== null && self::rows()->isNotEmpty();
    }

    /**
     * @return Collection<int, object{event_id: int, views: int|string}>
     */
    private static function rows(): Collection
    {
        return once(function (): Collection {
            $tenant = ManagementContext::tenant();

            return $tenant === null ? new Collection : app(DashboardSummary::class)->recentViews($tenant);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cards(): array
    {
        $rows = self::rows();
        $events = Event::query()->whereKey($rows->pluck('event_id'))->with(['media', 'venue'])->get()->keyBy('id');

        return $rows
            ->filter(fn (object $row): bool => $events->has($row->event_id))
            ->map(fn (object $row): array => ManagementContext::card($events[$row->event_id], null, ['view', 'statistics'], [
                ['heroicon-o-eye', trans_choice('manage.dashboard.views_count', (int) $row->views, ['count' => number_format((int) $row->views, 0, ',', '.')])],
            ]))
            ->values()
            ->all();
    }

    public function statisticsUrl(): ?string
    {
        return ManagementContext::statisticsUrl();
    }
}
