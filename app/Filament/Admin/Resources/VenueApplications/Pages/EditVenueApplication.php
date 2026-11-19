<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VenueApplications\Pages;

use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use App\Models\VenueApplication;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * La pagina di una richiesta: si legge dall'alto in basso e si decide
 * dall'intestazione, con le stesse azioni della riga dell'elenco. L'unico
 * campo che si scrive sono le note interne.
 */
class EditVenueApplication extends EditRecord
{
    protected static string $resource = VenueApplicationResource::class;

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();

        return $record instanceof VenueApplication ? $record->venue_name : parent::getTitle();
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        return $record instanceof VenueApplication
            ? __('venue_applications.admin.subheading', ['kind' => $record->kind()->label(), 'status' => $record->status->label()])
            : null;
    }

    public function getBreadcrumb(): string
    {
        return __('venue_applications.admin.breadcrumb');
    }

    /**
     * Si salvano solo le note: lo stato, il locale collegato e chi ha deciso
     * cambiano soltanto con «Approva» e «Rifiuta». Salvare una nota non è una
     * revisione, quindi non firma la richiesta.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ['notes' => isset($data['notes']) && is_string($data['notes']) ? $data['notes'] : null];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label(__('venue_applications.admin.save_notes'));
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label(__('venue_applications.admin.back_to_queue'));
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('venue_applications.admin.notes_saved');
    }

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            VenueApplicationResource::approveAction(),
            VenueApplicationResource::rejectAction(),
            ActionGroup::make([
                DeleteAction::make(),
            ])->label(__('venue_applications.admin.more'))->button()->color('gray'),
        ];
    }
}
