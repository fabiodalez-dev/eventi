<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Filament\Concerns\InteractsWithSocialStudio;
use App\Filament\Venue\Support\CurrentVenue;
use App\Models\Venue;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class Social extends Page
{
    use InteractsWithSocialStudio;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.social.studio';

    public function socialVenue(): ?Venue
    {
        return CurrentVenue::get();
    }
}
