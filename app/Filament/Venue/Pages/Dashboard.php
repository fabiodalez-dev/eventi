<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Filament\Venue\Support\CurrentVenue;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

/**
 * §10.1 — la prima schermata: *i tuoi eventi oggi, i prossimi, quante volte
 * sono stati visti*.
 *
 * Il titolo porta il nome del locale e non la parola "riepilogo": chi gestisce
 * più di un posto deve capire dov'è appena entrato senza cercarlo.
 */
class Dashboard extends BaseDashboard
{
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
