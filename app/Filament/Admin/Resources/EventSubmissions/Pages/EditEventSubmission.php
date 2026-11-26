<?php

namespace App\Filament\Admin\Resources\EventSubmissions\Pages;

use App\Actions\PublishEventSubmission;
use App\Actions\RejectEventSubmission;
use App\Enums\Permission;
use App\Enums\PriceType;
use App\Enums\SubmissionStatus;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\EventSubmissions\EventSubmissionResource;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditEventSubmission extends EditRecord
{
    protected static string $resource = EventSubmissionResource::class;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'price_type' => PriceType::Unknown->value];
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [];
    }

    // The original submission is immutable; only the explicit review actions write.
    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        abort(403);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')->label('Crea e pubblica')->color('primary')
                ->visible(fn (EventSubmission $record): bool => $record->status === SubmissionStatus::Pending && auth()->user()?->can(Permission::PublishEvents->value) && auth()->user()->can('create', Event::class))
                ->action(function (EventSubmission $record): void {
                    /** @var User $reviewer */
                    $reviewer = auth()->user();
                    $event = app(PublishEventSubmission::class)->handle($record, $reviewer, $this->form->getState());
                    Notification::make()->title('Evento pubblicato')->success()->send();
                    $this->redirect(EventResource::getUrl('edit', ['record' => $event]));
                }),
            Action::make('event')->label('Apri evento')->url(fn (EventSubmission $record): string => EventResource::getUrl('edit', ['record' => $record->event_id]))
                ->visible(fn (EventSubmission $record): bool => $record->event_id !== null),
            Action::make('reject')->label('Rifiuta proposta')->color('gray')
                ->visible(fn (EventSubmission $record): bool => $record->status === SubmissionStatus::Pending)
                ->schema([Textarea::make('notes')->label('Motivo del rifiuto (interno)')->required()->maxLength(RejectEventSubmission::NOTES_MAX)])
                ->action(function (EventSubmission $record, array $data): void {
                    /** @var User $reviewer */
                    $reviewer = auth()->user();
                    app(RejectEventSubmission::class)->handle($record, $reviewer, (string) $data['notes']);
                    $this->redirect(EventSubmissionResource::getUrl());
                }),
        ];
    }
}
