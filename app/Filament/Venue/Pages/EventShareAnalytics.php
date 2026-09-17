<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Filament\Shared\EventAnalyticsPage;
use App\Models\Venue;
use Filament\Facades\Filament;

class EventShareAnalytics extends EventAnalyticsPage
{
    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Venue && auth()->user()?->canAccessTenant(Filament::getTenant()) === true;
    }
}
