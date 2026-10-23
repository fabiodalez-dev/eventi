<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SearchMisses\Pages;

use App\Filament\Admin\Resources\SearchMisses\SearchMissResource;
use Filament\Resources\Pages\ManageRecords;

class ManageSearchMisses extends ManageRecords
{
    protected static string $resource = SearchMissResource::class;

    public function getTitle(): string
    {
        return __('admin.search_misses.plural');
    }

    public function getSubheading(): ?string
    {
        return __('admin.search_misses.hint');
    }
}
