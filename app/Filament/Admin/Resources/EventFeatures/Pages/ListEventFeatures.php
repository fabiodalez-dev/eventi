<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EventFeatures\Pages;

use App\Filament\Admin\Resources\EventFeatures\EventFeatureResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEventFeatures extends ListRecords
{
    protected static string $resource = EventFeatureResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
