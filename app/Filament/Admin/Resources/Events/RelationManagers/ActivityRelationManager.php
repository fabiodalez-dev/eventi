<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\RelationManagers;

use App\Services\Editorial\ActivityHistory;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * La cronologia delle modifiche (§9.2), letta da `activity_log`.
 *
 * È **sola lettura**: un registro che si possa correggere non è un registro.
 * Le righe le scrive il trait `LogsActivity` del modello: decisioni editoriali
 * per eventi e locali, modifiche amministrative per le abilitazioni sponsor.
 */
class ActivityRelationManager extends RelationManager
{
    protected static string $relationship = 'activitiesAsSubject';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.fields.activity_changes');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.fields.activity_date'))
                    ->panelMoment()
                    ->sortable(),

                TextColumn::make('event')
                    ->label(__('admin.fields.activity_event'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ActivityHistory::eventLabel($state)),

                TextColumn::make('causer.name')
                    ->label(__('admin.fields.activity_causer'))
                    ->placeholder(fn (Activity $record): string => $record->causer_id === null
                        ? __('admin.placeholders.system')
                        : __('admin.activity.unavailable_causer')),

                TextColumn::make('changes')
                    ->label(__('admin.fields.activity_changes'))
                    ->state(fn (Activity $record): string => ActivityHistory::describe($record))
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                /*
                 * «Ripristina»: rimette i valori di prima per i campi toccati
                 * da questa riga. Il registro resta intatto — il ripristino è
                 * una modifica come le altre e lascia la sua riga — ma chi ha
                 * rovinato una scheda per sbaglio non deve riscriverla a mano
                 * leggendo la cronologia.
                 */
                Action::make('restore')
                    ->label(__('admin.activity.restore'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.activity.restore_heading'))
                    ->modalDescription(fn (Activity $record): string => __('admin.activity.restore_description', ['fields' => implode(', ', array_keys(ActivityHistory::previous($record)))]))
                    ->visible(fn (Activity $record): bool => ActivityHistory::restorable($record, $this->getOwnerRecord()))
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false)
                    ->action(function (Activity $record): void {
                        if (! ActivityHistory::restore($this->getOwnerRecord(), $record)) {
                            Notification::make()->title(__('admin.activity.restore_empty'))->warning()->send();

                            return;
                        }
                        Notification::make()->title(__('admin.activity.restored'))->success()->send();
                    }),
            ])
            ->paginated([10, 25, 50]);
    }
}
