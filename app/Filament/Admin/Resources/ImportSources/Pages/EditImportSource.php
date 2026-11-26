<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ImportSources\Pages;

use App\Filament\Admin\Resources\ImportSources\ImportSourceResource;
use App\Models\ImportSource;
use App\Support\Import\ImportUrlOrigin;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditImportSource extends EditRecord
{
    protected static string $resource = ImportSourceResource::class;

    private bool $credentialsForgotten = false;

    /**
     * Le credenziali valgono per l'origine dell'indirizzo (schema, host,
     * porta): `DownloadsSource` le manda solo lì. Un indirizzo che cambia
     * origine senza che le credenziali vengano riscritte le porterebbe al
     * nuovo host alla prima anteprima, quindi si cancellano e lo si dice.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();
        if ($record instanceof ImportSource && ImportUrlOrigin::forgetsCredentials($record, $data)) {
            $data['credentials'] = null;
            $this->credentialsForgotten = true;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->credentialsForgotten) {
            Notification::make()->warning()->title(__('admin.import.credentials_forgotten'))->persistent()->send();
            $this->credentialsForgotten = false;
        }
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
