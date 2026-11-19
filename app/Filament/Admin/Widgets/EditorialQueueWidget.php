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

        $events = $dashboard->pendingEvents()->count();
        $venues = $dashboard->pendingVenues()->count();
        $applications = $dashboard->pendingApplications()->count();
        $reports = $dashboard->openReports()->count();

        /* Ogni riquadro dice in una frase che cosa aspetta una decisione; quando
           non aspetta niente resta grigio, così l'occhio va solo dove serve. */
        return [
            Stat::make(__('admin.dashboard.pending_events'), $events)
                ->description(trans_choice('pannelli-redazione.riepilogo.pending_events', $events))
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->color($events > 0 ? 'warning' : 'gray')
                ->url(EventResource::getUrl('index', [
                    'filters' => ['status' => ['value' => EventStatus::Pending->value]],
                ])),

            Stat::make(__('admin.dashboard.pending_venues'), $venues)
                ->description(trans_choice('pannelli-redazione.riepilogo.pending_venues', $venues))
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->color($venues > 0 ? 'warning' : 'gray')
                ->url(VenueResource::getUrl('index', [
                    'filters' => ['status' => ['value' => VenueStatus::Pending->value]],
                ])),

            Stat::make(__('admin.dashboard.pending_applications'), $applications)
                ->description(trans_choice('pannelli-redazione.riepilogo.pending_applications', $applications))
                ->icon(Heroicon::OutlinedEnvelopeOpen)
                ->color($applications > 0 ? 'warning' : 'gray')
                ->url(VenueApplicationResource::getUrl('index', [
                    'filters' => ['status' => ['value' => ApplicationStatus::Pending->value]],
                ])),

            Stat::make(__('admin.dashboard.open_reports'), $reports)
                ->description(trans_choice('pannelli-redazione.riepilogo.open_reports', $reports))
                ->icon(Heroicon::OutlinedFlag)
                ->color($reports > 0 ? 'danger' : 'gray')
                ->url(ReportResource::getUrl('index', [
                    'filters' => ['open' => ['isActive' => true]],
                ])),
        ];
    }
}
