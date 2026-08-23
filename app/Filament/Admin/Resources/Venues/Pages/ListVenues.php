<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Venues\Pages;

use App\Enums\VenueStatus;
use App\Filament\Admin\Resources\Venues\VenueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListVenues extends ListRecords
{
    protected static string $resource = VenueResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('admin.tabs.all')),
            'pending' => Tab::make(__('admin.tabs.pending'))
                ->modifyQueryUsing(fn ($query) => $query->where('status', VenueStatus::Pending)),
            'approved' => Tab::make(__('admin.tabs.approved'))
                ->modifyQueryUsing(fn ($query) => $query->where('status', VenueStatus::Approved)),
            'suspended' => Tab::make(__('admin.tabs.suspended'))
                ->modifyQueryUsing(fn ($query) => $query->where('status', VenueStatus::Suspended)),
        ];
    }
}
