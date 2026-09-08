<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\SponsorshipAnalyticsPage;

class SponsorshipAnalytics extends SponsorshipAnalyticsPage
{
    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) === true;
    }
}
