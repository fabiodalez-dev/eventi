<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\ImportSources\ImportSourceResource;
use App\Filament\Admin\Resources\Venues\VenueResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Qualità dei contenuti (§9.1 e §14.5): ciò che è già online ma non è pronto
 * per essere letto, e ciò che va guardato prima che diventi un problema.
 *
 * Nessuna di queste condizioni provoca un'azione automatica: la dashboard
 * indica i candidati, la decisione resta al moderatore (§14.4).
 */
class ContentQualityWidget extends EditorialWidget
{
    protected static ?int $sort = 3;

    protected function getHeading(): ?string
    {
        return __('admin.dashboard.quality_heading');
    }

    protected function getDescription(): ?string
    {
        return __('admin.dashboard.quality_description');
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $dashboard = $this->dashboard();

        return [
            Stat::make(__('admin.dashboard.missing_poster'), $dashboard->eventsWithoutPoster()->count())
                ->description(__('admin.dashboard.descriptions.missing_poster'))
                ->icon(Heroicon::OutlinedPhoto)
                ->url(EventResource::getUrl('index', [
                    'filters' => ['missing_poster' => ['isActive' => true]],
                ])),

            Stat::make(__('admin.dashboard.incomplete'), $dashboard->incompleteEvents()->count())
                ->description(__('admin.dashboard.descriptions.incomplete'))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('warning')
                ->url(EventResource::getUrl('index', [
                    'filters' => ['incomplete' => ['isActive' => true]],
                ])),

            Stat::make(__('admin.dashboard.possible_duplicates'), $dashboard->possibleDuplicates()->count())
                ->description(__('admin.dashboard.descriptions.possible_duplicates'))
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->url(EventResource::getUrl('index', [
                    'filters' => ['possible_duplicates' => ['isActive' => true]],
                ])),

            Stat::make(__('admin.dashboard.inactive_venues'), $dashboard->inactiveVenues()->count())
                ->description(__('admin.dashboard.descriptions.inactive_venues'))
                ->icon(Heroicon::OutlinedMoon)
                ->url(VenueResource::getUrl('index', [
                    'filters' => ['inactive' => ['isActive' => true]],
                ])),

            Stat::make(__('admin.dashboard.import_failures'), $dashboard->failedImports()->count())
                ->description(__('admin.dashboard.descriptions.import_failures'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('danger')
                ->url(ImportSourceResource::getUrl('index', [
                    'filters' => ['failed' => ['isActive' => true]],
                ])),
        ];
    }
}
