<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SponsorshipPrices\Pages;

use App\Filament\Admin\Resources\SponsorshipPrices\SponsorshipPriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPrices extends ListRecords
{
    protected static string $resource = SponsorshipPriceResource::class;

    /** @return array<mixed> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('promotions.prices.create'))];
    }

    /** Il titolo com'e' scritto nei testi, non con le iniziali maiuscole che Filament aggiunge da solo. */
    public function getTitle(): string
    {
        return __('promotions.prices.title');
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
