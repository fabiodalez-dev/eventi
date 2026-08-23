<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

/**
 * §9.1. I riquadri arrivano dalla scoperta automatica dei widget del pannello:
 * ognuno si dichiara con il proprio `$sort`, e nessuno viene disegnato finché
 * non esiste almeno una città.
 */
class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    public static function getNavigationLabel(): string
    {
        return __('admin.dashboard.title');
    }

    public function getTitle(): string
    {
        return __('admin.dashboard.title');
    }
}
