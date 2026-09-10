<?php

namespace App\Filament\Admin\Resources\EventSubmissions\Pages;

use App\Filament\Admin\Resources\EventSubmissions\EventSubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListEventSubmissions extends ListRecords
{
    protected static string $resource = EventSubmissionResource::class;
}
