<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Pages;

use App\Filament\Shared\AnalyticsDetailPage;
use App\Services\Analytics\ManagementAnalytics;
use Filament\Panel;

class Dashboard extends AnalyticsDetailPage
{
    protected static ?string $slug = 'dashboard';

    protected static bool $shouldRegisterNavigation = true;

    protected static ?int $navigationSort = -2;

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public function mount(?string $subjectType = null, ?int $subjectId = null): void
    {
        parent::mount('organizer', app(ManagementAnalytics::class)->subject()->id);
    }

    public static function getNavigationLabel(): string
    {
        return __('manage.dashboard.title');
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    /* Come nel pannello dei locali: il titolo e' il nome di chi e' appena
       entrato, non la parola «statistiche». */
    public function getTitle(): string
    {
        return app(ManagementAnalytics::class)->subject()->name;
    }

    public function getSubheading(): ?string
    {
        return __('manage.dashboard.subheading');
    }
}
