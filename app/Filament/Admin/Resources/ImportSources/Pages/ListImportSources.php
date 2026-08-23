<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ImportSources\Pages;

use App\Filament\Admin\Resources\ImportSources\ImportSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImportSources extends ListRecords
{
    protected static string $resource = ImportSourceResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
