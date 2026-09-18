<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Pages;

use App\Filament\Shared\EventAnalyticsPage;
use App\Models\Organizer;
use Filament\Facades\Filament;

class EventShareAnalytics extends EventAnalyticsPage
{
    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Organizer && auth()->user()?->canAccessTenant(Filament::getTenant()) === true;
    }
}
