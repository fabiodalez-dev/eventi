<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ImportSources\Pages;

use App\Filament\Admin\Resources\ImportSources\ImportSourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateImportSource extends CreateRecord
{
    protected static string $resource = ImportSourceResource::class;
}
