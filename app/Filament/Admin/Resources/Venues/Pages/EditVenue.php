<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Venues\Pages;

use App\Filament\Admin\Resources\Venues\VenueResource;
use App\Filament\Admin\Support\StructuredFields;
use App\Filament\Admin\Support\VenueModeration;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVenue extends EditRecord
{
    protected static string $resource = VenueResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['opening_hours'] = StructuredFields::openingHoursToRows($data['opening_hours'] ?? null);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['opening_hours'] = StructuredFields::openingHoursToMap($data['opening_hours'] ?? null);

        return $data;
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            ...VenueModeration::actions(),
            DeleteAction::make(),
        ];
    }
}
