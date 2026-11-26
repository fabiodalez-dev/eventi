<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Support\DeskLinks;
use App\Services\Editorial\DeskSummary;
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
        return DeskLinks::stats(app(DeskSummary::class)->quality($this->city()), [
            'missing_poster' => Heroicon::OutlinedPhoto,
            'incomplete' => Heroicon::OutlinedExclamationTriangle,
            'possible_duplicates' => Heroicon::OutlinedDocumentDuplicate,
            'inactive_venues' => Heroicon::OutlinedMoon,
            'import_failures' => Heroicon::OutlinedArrowDownTray,
        ]);
    }
}
