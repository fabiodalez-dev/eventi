<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VenueApplications\Pages;

use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVenueApplication extends EditRecord
{
    protected static string $resource = VenueApplicationResource::class;

    /**
     * Chi ha valutato la richiesta e quando: non si dichiarano, si registrano.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['reviewed_by'] = auth()->id();
        $data['reviewed_at'] = now();

        return $data;
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
