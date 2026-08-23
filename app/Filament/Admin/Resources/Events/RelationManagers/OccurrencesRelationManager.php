<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events\RelationManagers;

use App\Actions\UpdateOccurrencesAction;
use App\Enums\LineupRole;
use App\Enums\OccurrenceScope;
use App\Enums\OccurrenceStatus;
use App\Filament\Support\EventStatusPresentation;
use App\Models\EventOccurrence;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Le date dell'evento, e la distinzione che §9.2 chiede a voce alta:
 * **questa data soltanto** oppure **tutta la serie**.
 *
 * La domanda compare solo dove ha senso. Una data inserita a mano non
 * appartiene ad alcuna ricorrenza: lì il pannello non chiede nulla e lo dice,
 * invece di offrire una scelta che non cambierebbe niente.
 *
 * `business_date` ed `effective_ends_at` compaiono in tabella ma non nel
 * modulo: le calcola `EventOccurrenceObserver` a ogni salvataggio (§5 delle
 * convenzioni), e un campo compilabile le farebbe divergere dal motore
 * temporale al primo salvataggio distratto.
 */
class OccurrencesRelationManager extends RelationManager
{
    protected static string $relationship = 'occurrences';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.occurrence.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DateTimePicker::make('starts_at')
                    ->label(__('admin.fields.starts_at'))
                    ->seconds(false)
                    ->required(),

                DateTimePicker::make('ends_at')
                    ->label(__('admin.fields.ends_at'))
                    ->seconds(false)
                    ->after('starts_at'),

                DateTimePicker::make('doors_at')
                    ->label(__('admin.fields.doors_at'))
                    ->seconds(false),

                Toggle::make('is_all_day')
                    ->label(__('admin.fields.is_all_day')),

                Select::make('status')
                    ->label(__('admin.fields.occurrence_status'))
                    ->options(OccurrenceStatus::options())
                    ->required()
                    ->default(OccurrenceStatus::Scheduled->value),

                TextInput::make('capacity_left')
                    ->label(__('admin.fields.capacity_left'))
                    ->numeric()
                    ->minValue(0),

                Textarea::make('status_note')
                    ->label(__('admin.fields.status_note'))
                    ->rows(2)
                    ->columnSpanFull(),

                Repeater::make('lineups')
                    ->label(__('admin.resources.lineup.plural'))
                    ->relationship()
                    ->columns(4)
                    ->defaultItems(0)
                    ->addActionLabel(__('admin.actions.add_lineup'))
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label(__('admin.fields.name'))
                            ->required()
                            ->maxLength(255),

                        Select::make('role')
                            ->label(__('admin.fields.role'))
                            ->options(LineupRole::options())
                            ->required()
                            ->default(LineupRole::Live->value),

                        DateTimePicker::make('starts_at')
                            ->label(__('admin.fields.starts_at'))
                            ->seconds(false),

                        TextInput::make('url')
                            ->label(__('admin.fields.url'))
                            ->url()
                            ->maxLength(255),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('starts_at')
            ->columns([
                TextColumn::make('starts_at')
                    ->label(__('admin.fields.starts_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('business_date')
                    ->label(__('admin.fields.business_date'))
                    ->date('d/m/Y'),

                TextColumn::make('effective_ends_at')
                    ->label(__('admin.fields.effective_ends_at'))
                    ->dateTime('d/m/Y H:i'),

                TextColumn::make('status')
                    ->label(__('admin.fields.occurrence_status'))
                    ->badge()
                    ->formatStateUsing(fn (OccurrenceStatus $state): string => $state->label())
                    ->color(fn (OccurrenceStatus $state): string => EventStatusPresentation::occurrenceColor($state)),

                IconColumn::make('recurrence_id')
                    ->label(__('admin.resources.recurrence.label'))
                    ->boolean()
                    ->state(fn (EventOccurrence $record): bool => $record->recurrence_id !== null),

                IconColumn::make('is_exception')
                    ->label(__('admin.fields.is_exception'))
                    ->boolean(),

                TextColumn::make('lineups_count')
                    ->label(__('admin.resources.lineup.plural'))
                    ->counts('lineups')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('starts_at')
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.actions.add_occurrence')),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalDescription(fn (EventOccurrence $record): string => $record->recurrence_id === null
                        ? __('admin.scopes.no_series')
                        : __('admin.scopes.single_help')),

                Action::make('move')
                    ->label(__('admin.actions.move_occurrence'))
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->authorize(fn (EventOccurrence $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->schema(fn (EventOccurrence $record): array => [
                        TextInput::make('shift_minutes')
                            ->label(__('admin.fields.shift_minutes'))
                            ->numeric()
                            ->required(),
                        ...self::scopeField($record),
                    ])
                    ->action(function (EventOccurrence $record, array $data): void {
                        $changed = app(UpdateOccurrencesAction::class)->shift(
                            $record,
                            (int) $data['shift_minutes'],
                            self::scopeFrom($data),
                        );

                        self::notifyChanged($changed);
                    }),

                Action::make('cancel_occurrence')
                    ->label(__('admin.actions.cancel_occurrence'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->authorize(fn (EventOccurrence $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (EventOccurrence $record): bool => $record->status !== OccurrenceStatus::Cancelled)
                    ->schema(fn (EventOccurrence $record): array => [
                        Textarea::make('status_note')
                            ->label(__('admin.fields.status_note'))
                            ->rows(2),
                        ...self::scopeField($record),
                    ])
                    ->action(function (EventOccurrence $record, array $data): void {
                        $changed = app(UpdateOccurrencesAction::class)->changeStatus(
                            $record,
                            OccurrenceStatus::Cancelled,
                            $data['status_note'] ?? null,
                            self::scopeFrom($data),
                        );

                        self::notifyChanged($changed);
                    }),

                Action::make('sold_out')
                    ->label(fn (EventOccurrence $record): string => $record->status === OccurrenceStatus::SoldOut
                        ? __('admin.actions.mark_scheduled')
                        : __('admin.actions.mark_sold_out'))
                    ->icon(Heroicon::OutlinedTicket)
                    ->requiresConfirmation()
                    ->authorize(fn (EventOccurrence $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(function (EventOccurrence $record): void {
                        $changed = app(UpdateOccurrencesAction::class)->changeStatus(
                            $record,
                            $record->status === OccurrenceStatus::SoldOut
                                ? OccurrenceStatus::Scheduled
                                : OccurrenceStatus::SoldOut,
                            null,
                            OccurrenceScope::Single,
                        );

                        self::notifyChanged($changed);
                    }),

                DeleteAction::make(),
            ]);
    }

    /**
     * Il selettore compare solo quando c'è davvero una serie da toccare.
     *
     * @return array<Radio>
     */
    private static function scopeField(EventOccurrence $occurrence): array
    {
        if ($occurrence->recurrence_id === null) {
            return [];
        }

        return [
            Radio::make('scope')
                ->label(__('admin.scopes.label'))
                ->options(OccurrenceScope::options())
                ->descriptions([
                    OccurrenceScope::Single->value => OccurrenceScope::Single->helpText(),
                    OccurrenceScope::Series->value => OccurrenceScope::Series->helpText(),
                ])
                ->default(OccurrenceScope::Single->value)
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function scopeFrom(array $data): OccurrenceScope
    {
        return OccurrenceScope::tryFrom((string) ($data['scope'] ?? '')) ?? OccurrenceScope::Single;
    }

    private static function notifyChanged(int $changed): void
    {
        Notification::make()
            ->title($changed === 0
                ? __('admin.notifications.nothing_to_do')
                : __('admin.notifications.occurrences_updated', ['count' => $changed]))
            ->success()
            ->send();
    }
}
