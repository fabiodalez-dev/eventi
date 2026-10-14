<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SponsorshipPrices\Pages;

use App\Filament\Admin\Resources\SponsorshipPrices\SponsorshipPriceResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePrice extends CreateRecord
{
    protected static string $resource = SponsorshipPriceResource::class;
}
