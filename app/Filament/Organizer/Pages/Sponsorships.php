<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Pages;

use App\Filament\Shared\SponsorshipsPage;
use App\Models\Organizer;
use Filament\Facades\Filament;

/** Campagne, ordini e storico dell'organizzatore. */
class Sponsorships extends SponsorshipsPage
{
    protected function sponsor(): Organizer
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Organizer, 403);

        return $tenant->relationLoaded('city') ? $tenant : $tenant->load('city');
    }

    protected function analyticsUrl(): ?string
    {
        return null;
    }
}
