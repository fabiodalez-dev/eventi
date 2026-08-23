<?php

declare(strict_types=1);

namespace App\Filament\Venue\Resources\Events\Pages;

use App\Filament\Venue\Resources\Events\EventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    public function getTitle(): string
    {
        return __('manage.resources.event.plural');
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('manage.actions.new_event'))
                ->icon('heroicon-o-plus'),
        ];
    }
}
