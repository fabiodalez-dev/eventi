<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EventFeatures\Pages;

use App\Filament\Admin\Resources\EventFeatures\EventFeatureResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEventFeature extends CreateRecord
{
    protected static string $resource = EventFeatureResource::class;
}
