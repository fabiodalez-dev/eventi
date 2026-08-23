<?php

declare(strict_types=1);

namespace App\Filament\Venue\Widgets;

use App\Enums\StatsPeriod;
use App\Filament\Venue\Pages\Statistics;
use App\Filament\Venue\Resources\Events\EventResource;
use App\Filament\Venue\Support\CurrentVenue;
use App\Queries\VenueDashboardQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * I tre numeri di §10.1, nell'ordine esatto in cui il piano li scrive:
 * *oggi*, *prossimi*, *visualizzazioni negli ultimi 30 giorni*.
 *
 * I primi due li conta il motore temporale — "oggi" ha una sola definizione in
 * tutto il prodotto (§8.1) — il terzo somma le righe aggregate di
 * `event_views_daily`. Ognuno porta dove serve: agli eventi il primo e il
 * secondo, alle statistiche il terzo.
 */
class VenueOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $dashboard = VenueDashboardQuery::for(CurrentVenue::get());
        $period = StatsPeriod::Month;

        return [
            Stat::make(__('manage.dashboard.today'), Number::format($dashboard->today(), locale: 'it'))
                ->description(__('manage.dashboard.today_hint'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('primary')
                ->url(EventResource::getUrl('index')),

            Stat::make(__('manage.dashboard.upcoming'), Number::format($dashboard->upcoming(), locale: 'it'))
                ->description(__('manage.dashboard.upcoming_hint'))
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->url(EventResource::getUrl('index')),

            Stat::make(__('manage.dashboard.views'), Number::format($dashboard->views($period), locale: 'it'))
                ->description(__('manage.dashboard.views_hint', ['days' => $period->days()]))
                ->icon(Heroicon::OutlinedEye)
                ->url(Statistics::getUrl()),
        ];
    }
}
