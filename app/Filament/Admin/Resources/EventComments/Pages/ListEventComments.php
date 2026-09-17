<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EventComments\Pages;

use App\Filament\Admin\Resources\EventComments\EventCommentResource;
use Filament\Resources\Pages\ListRecords;

class ListEventComments extends ListRecords
{
    protected static string $resource = EventCommentResource::class;
}
