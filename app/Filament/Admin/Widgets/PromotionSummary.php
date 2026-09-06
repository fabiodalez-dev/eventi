<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PromotionSummary extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->can('viewAny', SponsorshipGrant::class) ?? false;
    }

    protected function getStats(): array
    {
        return [
            Stat::make(__('promotions.active'), SponsorshipGrant::active()->count()),
            Stat::make(__('promotions.expiring'), SponsorshipGrant::active()->where('ends_at', '<=', now()->addDays(7))->count()),
            Stat::make(__('promotions.income'), number_format(SponsorshipGrant::where('complimentary', false)->where('paid_at', '<=', now())->sum('amount_cents') / 100, 2, ',', '.')),
            Stat::make(__('promotions.views'), Sponsorship::sum('impressions')),
            Stat::make(__('promotions.clicks'), Sponsorship::sum('clicks')),
        ];
    }
}
