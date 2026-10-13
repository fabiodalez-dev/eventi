<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\SponsorshipGrant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PromotionSummary extends StatsOverviewWidget
{
    protected static ?int $sort = 6;

    /* Niente aggiornamento automatico ogni cinque secondi: ogni giro era una
       richiesta completa con tutte le sue query, e i numeri cambiano a ore. */
    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', SponsorshipGrant::class) ?? false;
    }

    protected function getHeading(): ?string
    {
        return __('promotions.title');
    }

    protected function getColumns(): int
    {
        return 3;
    }

    /*
     * Tre numeri: le abilitazioni, quelle che scadono, gli incassi. Viste e
     * clic totali da inizio storia stavano qui accanto, ma li racconta meglio
     * il riquadro delle sponsorizzazioni, sulla settimana corrente.
     */
    protected function getStats(): array
    {
        return [
            Stat::make(__('promotions.active'), SponsorshipGrant::active()->count()),
            Stat::make(__('promotions.expiring'), SponsorshipGrant::active()->where('ends_at', '<=', now()->addDays(7))->count()),
            Stat::make(__('promotions.income'), number_format(SponsorshipGrant::where('complimentary', false)->where('paid_at', '<=', now())->sum('amount_cents') / 100, 2, ',', '.')),
        ];
    }
}
