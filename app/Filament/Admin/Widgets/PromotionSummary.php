<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Support\DeskLinks;
use App\Models\City;
use App\Models\SponsorshipGrant;
use App\Services\Editorial\DeskSummary;
use App\Support\Sponsorship\DashboardCity;
use Filament\Widgets\StatsOverviewWidget;

class PromotionSummary extends StatsOverviewWidget
{
    protected static ?int $sort = 6;

    /* Niente aggiornamento automatico ogni cinque secondi: ogni giro era una
       richiesta completa con tutte le sue query, e i numeri cambiano a ore. */
    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return (auth()->user()?->can('viewAny', SponsorshipGrant::class) ?? false) && DashboardCity::resolve() instanceof City;
    }

    protected function getHeading(): ?string
    {
        $city = DashboardCity::resolve();

        return $city === null ? __('promotions.title') : __('promotions.title_city', ['city' => $city->name]);
    }

    protected function getColumns(): int
    {
        return 3;
    }

    /*
     * Tre numeri: le abilitazioni, quelle che scadono, gli incassi. Viste e
     * clic totali da inizio storia stavano qui accanto, ma li racconta meglio
     * il riquadro delle sponsorizzazioni, sulla settimana corrente.
     *
     * Le abilitazioni dei committenti della citta' della dashboard, come gli
     * altri riquadri: le conta `DeskSummary`, lo stesso che risponde all'app.
     */
    protected function getStats(): array
    {
        return DeskLinks::stats(app(DeskSummary::class)->promotion(DashboardCity::resolve()));
    }
}
