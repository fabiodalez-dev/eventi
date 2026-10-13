<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Pages;

use App\Filament\Shared\ContentAnalyticsPage;

class Statistics extends ContentAnalyticsPage
{
    public static function getNavigationGroup(): ?string
    {
        return __('manage.navigation.analytics');
    }

    protected static ?int $navigationSort = 1;
}
