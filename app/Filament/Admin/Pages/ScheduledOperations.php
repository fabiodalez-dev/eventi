<?php

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class ScheduledOperations extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $slug = 'cron-e-programmazioni';

    protected static ?string $title = 'Cron e programmazioni';

    protected string $view = 'filament.scheduled-operations';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.system');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'super_admin']) ?? false;
    }
}
