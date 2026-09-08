<?php

namespace App\Filament\Admin\Resources\Organizers\Pages;

use App\Filament\Admin\Resources\Organizers\OrganizerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageOrganizers extends ManageRecords
{
    protected static string $resource = OrganizerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
