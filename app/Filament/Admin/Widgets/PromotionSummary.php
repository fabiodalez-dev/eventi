<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\City;
use App\Models\SponsorshipGrant;
use App\Support\Sponsorship\DashboardCity;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

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
     */
    protected function getStats(): array
    {
        /* Le abilitazioni dei committenti della citta' della dashboard, come
           gli altri riquadri: un locale o un'organizzazione di quella citta'. */
        $cityId = DashboardCity::resolve()?->getKey() ?? 0;
        $inCity = fn (Builder $q): Builder => $q->where(fn (Builder $sponsor) => $sponsor
            ->whereHas('venue', fn (Builder $venue) => $venue->where('city_id', $cityId))
            ->orWhereHas('organizer', fn (Builder $organizer) => $organizer->where('city_id', $cityId)));

        return [
            Stat::make(__('promotions.active'), $inCity(SponsorshipGrant::active())->count()),
            Stat::make(__('promotions.expiring'), $inCity(SponsorshipGrant::active())->where('ends_at', '<=', now()->addDays(7))->count()),
            Stat::make(__('promotions.income'), number_format($inCity(SponsorshipGrant::query())->where('complimentary', false)->where('paid_at', '<=', now())->sum('amount_cents') / 100, 2, ',', '.')),
        ];
    }
}
