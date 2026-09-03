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
            /*
             * Le azioni di moderazione in chiaro, e l'eliminazione dopo di
             * loro e defilata: sospendere e' ordinario e si annulla,
             * eliminare e' definitivo. Prima era il contrario — l'unica cosa
             * visibile era «Elimina», il resto stava dietro un menu senza
             * etichetta.
             */
            ...VenueModeration::headerActions(),
            DeleteAction::make()->color('gray'),
        ];
    }
}
