<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Enums\EventStatus;
use App\Filament\Admin\Resources\Events\EventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

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
                ->modifyQueryUsing(fn ($query) => $query->where('status', EventStatus::Pending)),
            'published' => Tab::make(__('admin.tabs.published'))
                ->modifyQueryUsing(fn ($query) => $query->where('status', EventStatus::Published)),
            'draft' => Tab::make(__('admin.tabs.draft'))
                ->modifyQueryUsing(fn ($query) => $query->where('status', EventStatus::Draft)),
            'cancelled' => Tab::make(__('admin.tabs.cancelled'))
                ->modifyQueryUsing(fn ($query) => $query->where('status', EventStatus::Cancelled)),
        ];
    }
}
