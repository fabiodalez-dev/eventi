<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Filament\Shared\AnalyticsDetailPage;
use App\Filament\Venue\Support\CurrentVenue;
use App\Filament\Venue\Widgets\MissingLocationWidget;
use App\Filament\Venue\Widgets\RecentViewsWidget;
use App\Filament\Venue\Widgets\ShortcutsWidget;
use App\Filament\Venue\Widgets\TodayScheduleWidget;
use App\Filament\Venue\Widgets\UpcomingEventsWidget;
use App\Filament\Venue\Widgets\VenueOverviewWidget;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;

/**
 * §10.1 — la prima schermata: *i tuoi eventi oggi, i prossimi, quante volte
 * sono stati visti*, e da qui tutte le altre pagine del pannello.
 *
 * È di proposito la pagina delle statistiche del locale
 * (`AnalyticsDetailPage`); i riquadri del riepilogo stanno sopra il report.
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
        $monday = now($venue->city->timezone)->startOfWeek();

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

    /**
     * Sopra le statistiche del locale, in quest'ordine: l'avviso della mappa
     * (solo se serve), il cartellone in tre numeri, le serate di oggi, i
     * prossimi eventi con la locandina, le ultime visite e le scorciatoie
     * verso tutte le pagine. Sotto resta il report del locale.
     *
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            MissingLocationWidget::class,
            VenueOverviewWidget::class,
            TodayScheduleWidget::class,
            UpcomingEventsWidget::class,
            RecentViewsWidget::class,
            ShortcutsWidget::class,
        ];
    }

    /** Una colonna: un riquadro per riga, a ogni larghezza. */
    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
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
