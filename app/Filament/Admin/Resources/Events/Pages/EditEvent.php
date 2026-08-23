<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\Pages;

use App\Actions\GenerateOccurrencesAction;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Support\EventModeration;
use App\Models\Event;
use App\Models\EventRecurrence;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    /**
     * @return array<mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            ...EventModeration::actions(),
            $this->generateOccurrencesAction(),
            DeleteAction::make(),
        ];
    }

    /**
     * Materializza le date delle ricorrenze dell'evento.
     *
     * L'azione è idempotente per costruzione (D20, D22): rieseguirla non
     * aggiunge doppioni e non tocca le date già modificate a mano. È lo stesso
     * comportamento del comando pianificato mensile, chiamato dallo stesso
     * punto — non una seconda implementazione per il pannello.
     */
    private function generateOccurrencesAction(): Action
    {
        return Action::make('generate_occurrences')
            ->label(__('admin.actions.generate_occurrences'))
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->requiresConfirmation()
            ->authorize(fn (Event $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->visible(fn (Event $record): bool => $record->recurrences()->exists())
            ->action(function (Event $record): void {
                $action = app(GenerateOccurrencesAction::class);
                $created = 0;

                foreach ($record->recurrences as $recurrence) {
                    /** @var EventRecurrence $recurrence */
                    $created += $action($recurrence);
                }

                Notification::make()
                    ->title($created === 0
                        ? __('admin.notifications.nothing_to_do')
                        : __('admin.notifications.occurrences_generated', ['count' => $created]))
                    ->success()
                    ->send();
            });
    }
}
