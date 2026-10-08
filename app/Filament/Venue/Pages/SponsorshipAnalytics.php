<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Filament\Shared\SponsorshipAnalyticsPage;

class SponsorshipAnalytics extends SponsorshipAnalyticsPage
{
    public static function getNavigationGroup(): ?string
    {
        return __('manage.navigation.analytics');
    }

    protected static ?int $navigationSort = 3;
}
