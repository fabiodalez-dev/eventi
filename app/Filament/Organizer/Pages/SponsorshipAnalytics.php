<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Pages;

use App\Filament\Shared\SponsorshipAnalyticsPage;
use Filament\Facades\Filament;

/**
 * Le statistiche delle sponsorizzazioni per l'organizzazione: le campagne
 * sugli eventi che firma. Prima c'erano solo nel pannello del locale.
 */
class SponsorshipAnalytics extends SponsorshipAnalyticsPage
{
    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('manage.navigation.analytics');
    }

    /** Vero quando il pannello dell'organizzazione ha registrato la pagina (vedi `OrganizerPanelProvider`). */
    public static function isRegistered(): bool
    {
        return in_array(static::class, Filament::getPanel('organizer')->getPages(), true);
    }
}
