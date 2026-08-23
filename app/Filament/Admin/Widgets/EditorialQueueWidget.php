<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\ApplicationStatus;
use App\Enums\EventStatus;
use App\Enums\VenueStatus;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\Reports\ReportResource;
use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use App\Filament\Admin\Resources\Venues\VenueResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ciò che aspetta una decisione (§9.1). Ogni riquadro apre la lista già
 * filtrata sulla stessa condizione che ha prodotto il numero.
 */
class EditorialQueueWidget extends EditorialWidget
{
    protected static ?int $sort = 1;

    protected function getHeading(): ?string
    {
        return __('admin.dashboard.queue_heading');
    }

    protected function getDescription(): ?string
    {
        return __('admin.dashboard.queue_description');
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $dashboard = $this->dashboard();

        return [
            Stat::make(__('admin.dashboard.pending_events'), $dashboard->pendingEvents()->count())
                ->description(__('admin.dashboard.descriptions.pending_events'))
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->color('warning')
                ->url(EventResource::getUrl('index', [
                    'filters' => ['status' => ['value' => EventStatus::Pending->value]],
                ])),

            Stat::make(__('admin.dashboard.pending_venues'), $dashboard->pendingVenues()->count())
                ->description(__('admin.dashboard.descriptions.pending_venues'))
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->color('warning')
                ->url(VenueResource::getUrl('index', [
                    'filters' => ['status' => ['value' => VenueStatus::Pending->value]],
                ])),

            Stat::make(__('admin.dashboard.pending_applications'), $dashboard->pendingApplications()->count())
                ->icon(Heroicon::OutlinedEnvelopeOpen)
                ->url(VenueApplicationResource::getUrl('index', [
                    'filters' => ['status' => ['value' => ApplicationStatus::Pending->value]],
                ])),

            Stat::make(__('admin.dashboard.open_reports'), $dashboard->openReports()->count())
                ->description(__('admin.dashboard.descriptions.open_reports'))
                ->icon(Heroicon::OutlinedFlag)
                ->color('danger')
                ->url(ReportResource::getUrl('index', [
                    'filters' => ['open' => ['isActive' => true]],
                ])),
        ];
    }
}
