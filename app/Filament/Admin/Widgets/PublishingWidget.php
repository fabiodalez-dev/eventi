<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Support\DeskLinks;
use App\Services\Editorial\DeskSummary;
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
        return DeskLinks::stats(app(DeskSummary::class)->publishing($this->city()), [
            'published_today' => Heroicon::OutlinedCheckBadge,
            'published_this_week' => Heroicon::OutlinedCalendarDays,
            'scheduled_today' => Heroicon::OutlinedClock,
            'cancelled_events' => Heroicon::OutlinedXCircle,
        ]);
    }
}
