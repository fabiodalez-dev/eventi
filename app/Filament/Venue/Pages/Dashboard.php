<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Filament\Shared\AnalyticsDetailPage;
use App\Filament\Venue\Support\CurrentVenue;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;

/**
 * §10.1 — la prima schermata: *i tuoi eventi oggi, i prossimi, quante volte
 * sono stati visti*.
 *
 * Il titolo porta il nome del locale e non la parola "riepilogo": chi gestisce
 * più di un posto deve capire dov'è appena entrato senza cercarlo.
 */
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
        parent::mount('venue', CurrentVenue::get()->id);
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        $venue = CurrentVenue::get();
        $monday = now($venue->city?->timezone ?? config('app.timezone'))->startOfWeek();

        return [
            Action::make('programme')
                ->label(__('venues.programme.action'))
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->tooltip(__('venues.programme.action_hint'))
                ->url(route('venues.programme', $venue->slug))
                ->openUrlInNewTab(),
            Action::make('programme_next')
                ->label(__('venues.programme.next_week'))
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(route('venues.programme', ['slug' => $venue->slug, 'settimana' => $monday->addWeek()->toDateString()]))
                ->openUrlInNewTab(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return Filament::getWidgets();
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    public static function getNavigationLabel(): string
    {
        return __('manage.dashboard.title');
    }

    public function getTitle(): string
    {
        return CurrentVenue::get()->name;
    }

    public function getSubheading(): ?string
    {
        return __('manage.dashboard.subheading');
    }
}
