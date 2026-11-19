<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\NewRegistrationsWidget;
use App\Filament\Admin\Widgets\ReturnRatesWidget;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * §9.1. I riquadri arrivano dalla scoperta automatica dei widget del pannello:
 * ognuno si dichiara con il proprio `$sort`, e nessuno viene disegnato finché
 * non esiste almeno una città.
 *
 * Fa eccezione «Nuove iscrizioni», che non dipende dalla città e sta subito
 * prima di «Ritorno delle persone»: le due sezioni parlano delle persone e si
 * leggono insieme. A parità di `$sort` l'ordine dipenderebbe dal nome del
 * file, quindi lo fisso qui.
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

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        $widgets = array_values(array_filter(
            parent::getWidgets(),
            fn (string|WidgetConfiguration $widget): bool => $this->normalizeWidgetClass($widget) !== NewRegistrationsWidget::class,
        ));

        $position = array_search(ReturnRatesWidget::class, array_map(fn (string|WidgetConfiguration $widget): string => $this->normalizeWidgetClass($widget), $widgets), true);
        array_splice($widgets, $position === false ? count($widgets) : $position, 0, [NewRegistrationsWidget::class]);

        return $widgets;
    }
}
