<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\EventsCalendarWidget;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * La pagina che ospita il calendario della redazione.
 *
 * Sta accanto a «Eventi» e non dentro, perché non è un altro modo di
 * guardare quell'elenco: è la domanda opposta. L'elenco risponde a «quando è
 * questo evento», il calendario a «cosa succede giovedì» — e i buchi nel
 * programma si vedono solo sul secondo.
 */
class EventsCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'calendario';

    protected string $view = 'filament.admin.pages.events-calendar';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.calendar');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.content');
    }

    public function getTitle(): string
    {
        return __('admin.navigation.calendar');
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [EventsCalendarWidget::class];
    }

    /** A piena larghezza: un calendario stretto non si legge. */
    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
