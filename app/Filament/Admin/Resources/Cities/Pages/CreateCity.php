<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cities\Pages;

use App\Filament\Admin\Resources\Cities\CityResource;
use App\Filament\Concerns\HasFeminineCreateTitle;
use App\Support\CitySettings;
use Filament\Resources\Pages\CreateRecord;

class CreateCity extends CreateRecord
{
    use HasFeminineCreateTitle;

    protected static string $resource = CityResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['settings'] = CitySettings::toMap($data['settings'] ?? null);

        return $data;
    }
}
