<?php

namespace App\Filament\Organizer\Resources\Events\Pages;

use App\Enums\EventStatus;
use App\Filament\Organizer\Resources\Events\EventResource;
use App\Filament\Shared\EventAnalyticsNavigation;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Services\Events\EventSubmission;
use App\Services\Events\OrganizerEventEditor;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditEvent extends EditRecord
{
    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }

    public function getSubNavigation(): array
    {
        return EventAnalyticsNavigation::items((int) $this->getRecord()->getKey(), false, true);
    }

    protected static string $resource = EventResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Editing published content requires renewed editorial approval: la regola sta in `OrganizerEventEditor`.
        $tenant = Filament::getTenant();
        $user = auth()->user();
        if (! $tenant instanceof Organizer || ! $user instanceof User) {
            return $data;
        }

        return app(OrganizerEventEditor::class)->prepareUpdate($this->eventRecord(), $tenant, $user, $data)[0];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label('Anteprima')->url(fn () => route('events.preview', $this->record))->openUrlInNewTab(),
            Action::make('submit')->label('Invia alla redazione')->visible(fn () => in_array($this->eventRecord()->status, [EventStatus::Draft, EventStatus::Rejected], true))
                ->requiresConfirmation()->action(function (): void {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);
                    // La regola sta in `EventSubmission`, la stessa che usa l'API di gestione.
                    try {
                        app(EventSubmission::class)->sendToEditors($this->eventRecord(), $user);
                    } catch (ValidationException) {
                        Notification::make()->danger()->title('Aggiungi almeno una data prima di inviare')->send();

                        return;
                    }
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
