<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Venues\Pages;

use App\Filament\Admin\Resources\Venues\VenueResource;
use App\Filament\Admin\Support\StructuredFields;
use Filament\Resources\Pages\CreateRecord;

class CreateVenue extends CreateRecord
{
    protected static string $resource = VenueResource::class;

    /**
     * Gli orari di apertura si compilano come righe e si salvano nel formato
     * di D19: la traduzione avviene al confine del modulo, mai dentro il
     * ciclo di vita del ripetitore.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['opening_hours'] = StructuredFields::openingHoursToMap($data['opening_hours'] ?? null);

        return $data;
    }
}
