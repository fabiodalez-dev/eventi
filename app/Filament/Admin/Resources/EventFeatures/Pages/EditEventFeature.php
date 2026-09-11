<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EventFeatures\Pages;

use App\Filament\Admin\Resources\EventFeatures\EventFeatureResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEventFeature extends EditRecord
{
    protected static string $resource = EventFeatureResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
