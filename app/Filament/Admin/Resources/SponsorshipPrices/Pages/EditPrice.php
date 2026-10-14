<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SponsorshipPrices\Pages;

use App\Filament\Admin\Resources\SponsorshipPrices\SponsorshipPriceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPrice extends EditRecord
{
    protected static string $resource = SponsorshipPriceResource::class;

    /** @return array<mixed> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
