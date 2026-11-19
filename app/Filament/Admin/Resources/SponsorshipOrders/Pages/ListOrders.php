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

    /** Il titolo com'e' scritto nei testi, non con le iniziali maiuscole che Filament aggiunge da solo. */
    public function getTitle(): string
    {
        return __('promotions.orders.title');
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
