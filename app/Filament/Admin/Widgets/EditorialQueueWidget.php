<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Support\DeskLinks;
use App\Services\Editorial\DeskSummary;
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
        /* Ogni riquadro dice in una frase che cosa aspetta una decisione; quando
           non aspetta niente resta grigio, così l'occhio va solo dove serve.
           Numeri, frasi, colori e destinazioni li dà `DeskSummary`, lo stesso
           che risponde all'app. */
        return DeskLinks::stats(app(DeskSummary::class)->queue($this->city()), [
            'pending_events' => Heroicon::OutlinedInboxArrowDown,
            'pending_venues' => Heroicon::OutlinedBuildingStorefront,
            'pending_applications' => Heroicon::OutlinedEnvelopeOpen,
            'open_reports' => Heroicon::OutlinedFlag,
        ]);
    }
}
