<?php

namespace App\Filament\Organizer\Resources\Events\Pages;

use App\Enums\EventStatus;
use App\Filament\Organizer\Resources\Events\EventResource;
use App\Models\Event;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Gate;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Editing published content requires renewed editorial approval.
        if ($this->eventRecord()->status === EventStatus::Published) {
            $data['status'] = 'pending';
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label('Anteprima')->url(fn () => route('events.preview', $this->record))->openUrlInNewTab(),
            Action::make('submit')->label('Invia alla redazione')->visible(fn () => in_array($this->eventRecord()->status, [EventStatus::Draft, EventStatus::Rejected], true))
                ->requiresConfirmation()->action(function (): void {
                    Gate::authorize('update', $this->record);
                    if (! $this->eventRecord()->occurrences()->exists()) {
                        Notification::make()->danger()->title('Aggiungi almeno una data prima di inviare')->send();

                        return;
                    }
                    $this->record->update(['status' => 'pending']);
                    $this->refreshFormData(['status']);
                }),
        ];
    }

    private function eventRecord(): Event
    {
        $record = $this->getRecord();
        if (! $record instanceof Event) {
            throw new \LogicException('Expected event');
        }

        return $record;
    }
}
