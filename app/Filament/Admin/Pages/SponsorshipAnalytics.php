<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\SponsorshipAnalyticsPage;

class SponsorshipAnalytics extends SponsorshipAnalyticsPage
{
    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.promotion');
    }

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) === true;
    }
}
