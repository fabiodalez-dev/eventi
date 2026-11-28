<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Filament\Admin\Resources\Events\EventResource;
use App\Services\Events\EditorialEventEditor;
use Filament\Resources\Pages\CreateRecord;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    /**
     * L'autore non è un campo del modulo: è chi ha premuto il pulsante.
     * Chiederlo lascerebbe scegliere un nome qualsiasi, e `created_by` serve
     * proprio a sapere chi ha inserito davvero la scheda. La regola è la stessa
     * dell'app (`EditorialEventEditor::prepareCreate`).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $id = auth()->id();

        return EditorialEventEditor::prepareCreate($data, $id === null ? null : (int) $id);
    }
}
