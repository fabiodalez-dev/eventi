<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cities\Pages;

use App\Filament\Admin\Resources\Cities\CityResource;
use App\Models\City;
use App\Support\Catalog\CatalogAudit;
use App\Support\CitySettings;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCity extends EditRecord
{
    protected static string $resource = CityResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['settings'] = CitySettings::toRows($data['settings'] ?? null);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['settings'] = CitySettings::toMap($data['settings'] ?? null);

        return $data;
    }

    /** La modifica nel registro di sicurezza, con il valore di prima e quello nuovo, come dall'app. */
    protected function afterSave(): void
    {
        $record = $this->getRecord();
        if ($record instanceof City) {
            CatalogAudit::cityChanged($record);
        }
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
