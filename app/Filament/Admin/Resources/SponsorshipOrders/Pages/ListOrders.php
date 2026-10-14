<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SponsorshipOrders\Pages;

use App\Filament\Admin\Resources\SponsorshipOrders\SponsorshipOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = SponsorshipOrderResource::class;

    /** @return array<mixed> */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
