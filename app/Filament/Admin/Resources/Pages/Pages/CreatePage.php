<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pages\Pages;

use App\Filament\Admin\Resources\Pages\PageResource;
use App\Filament\Concerns\HasFeminineCreateTitle;
use Filament\Resources\Pages\CreateRecord;

class CreatePage extends CreateRecord
{
    use HasFeminineCreateTitle;

    protected static string $resource = PageResource::class;
}
