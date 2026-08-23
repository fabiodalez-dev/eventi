<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\EventStatus;
use App\Filament\Admin\Resources\Events\EventResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Quanto è uscito e quanto è in cartellone (§9.1).
 *
 * "Pubblicati oggi" e "in programma oggi" sono due domande diverse e vanno
 * tenute distinte: la prima riguarda l'atto della redazione, la seconda il
 * cartellone della giornata evento — e quest'ultima la risponde soltanto
 * `EventOccurrenceQuery` (§3 delle convenzioni).
 */
class PublishingWidget extends EditorialWidget
{
    protected static ?int $sort = 2;

    protected function getHeading(): ?string
    {
        return __('admin.dashboard.published_heading');
    }

    protected function getDescription(): ?string
    {
        return __('admin.dashboard.published_description');
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $dashboard = $this->dashboard();
        $city = static::resolveCity();

        return [
            Stat::make(__('admin.dashboard.published_today'), $dashboard->publishedToday()->count())
                ->description(__('admin.dashboard.descriptions.published_today', ['city' => $city->name ?? '']))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->url(EventResource::getUrl('index', [
                    'filters' => ['published_today' => ['isActive' => true]],
                ])),

            Stat::make(__('admin.dashboard.published_this_week'), $dashboard->publishedThisWeek()->count())
                ->description(__('admin.dashboard.descriptions.published_this_week'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->url(EventResource::getUrl('index', [
                    'filters' => ['published_this_week' => ['isActive' => true]],
                ])),

            Stat::make(__('admin.dashboard.scheduled_today'), $dashboard->scheduledToday())
                ->description(__('admin.dashboard.descriptions.scheduled_today'))
                ->icon(Heroicon::OutlinedClock),

            Stat::make(__('admin.dashboard.cancelled_events'), $dashboard->cancelledEvents()->count())
                ->description(__('admin.dashboard.descriptions.cancelled_events'))
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->url(EventResource::getUrl('index', [
                    'filters' => ['status' => ['value' => EventStatus::Cancelled->value]],
                ])),
        ];
    }
}
