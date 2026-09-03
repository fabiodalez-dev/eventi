<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Enums\EventStatus;
use App\Filament\Admin\Resources\Events\EventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use pxlrbt\FilamentExcel\Actions\Pages\ExportAction;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            /*
             * L'esportazione dell'elenco, coi filtri applicati.
             *
             * Esporta **quello che si sta guardando**: se la scheda «In
             * attesa» è aperta e c'è una ricerca in corso, il file contiene
             * quelle righe. Un'esportazione che ignora i filtri produce un
             * foglio da millequattrocento righe a chi ne voleva dodici, e chi
             * lo riceve non ha modo di sapere che non è quello che aveva
             * chiesto.
             */
            ExportAction::make()
                ->label(__('admin.actions.export'))
                ->color('gray'),

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
