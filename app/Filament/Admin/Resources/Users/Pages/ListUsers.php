<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use pxlrbt\FilamentExcel\Actions\Pages\ExportAction;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

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
}
