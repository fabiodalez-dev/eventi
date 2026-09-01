<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Sponsorships\Pages;

use App\Filament\Admin\Resources\Sponsorships\SponsorshipResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSponsorships extends ListRecords
{
    protected static string $resource = SponsorshipResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
