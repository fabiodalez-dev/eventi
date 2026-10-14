<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Filament\Shared\SponsorshipsPage;
use App\Filament\Venue\Support\CurrentVenue;
use App\Models\Venue;

/** Campagne, ordini e storico del locale. */
class Sponsorships extends SponsorshipsPage
{
    protected function sponsor(): Venue
    {
        return CurrentVenue::get();
    }

    protected function analyticsUrl(): ?string
    {
        return SponsorshipAnalytics::getUrl();
    }
}
