<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VenueApplications\Pages;

use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListVenueApplications extends ListRecords
{
    protected static string $resource = VenueApplicationResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
