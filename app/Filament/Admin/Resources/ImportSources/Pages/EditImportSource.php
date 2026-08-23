<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ImportSources\Pages;

use App\Filament\Admin\Resources\ImportSources\ImportSourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditImportSource extends EditRecord
{
    protected static string $resource = ImportSourceResource::class;

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
