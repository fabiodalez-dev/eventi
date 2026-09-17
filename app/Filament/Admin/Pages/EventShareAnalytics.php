<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Shared\EventAnalyticsPage;

class EventShareAnalytics extends EventAnalyticsPage
{
    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) === true;
    }
}
