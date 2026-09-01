<?php

declare(strict_types=1);

namespace App\Filament\Venue\Resources\Events\RelationManagers;

use App\Actions\UpdateOccurrencesAction;
use App\Enums\LineupRole;
use App\Enums\OccurrenceScope;
use App\Enums\OccurrenceStatus;
use App\Filament\Support\EventStatusPresentation;
use App\Filament\Venue\Support\CurrentVenue;
use App\Models\Event;
use App\Models\EventOccurrence;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Le date dell'evento, nella versione da bancone: aggiungere una data,
 * spostarla, annullarla, dire chi suona.
 *
 * Rispetto alla stessa sezione in redazione mancano le colonne calcolate
 * (`business_date`, `effective_ends_at`) e la scelta dello stato fra cinque
 * voci: a un gestore servono due gesti — *annullo* e *tutto esaurito* — e le
 * conseguenze sul motore temporale le scrive l'observer.
 *
 * Resta invece intatta la domanda di §9.2, perché senza non si governa un
 * cartellone ricorrente: **questa data soltanto, o tutte quelle che seguono?**
 * La risposta la applica `UpdateOccurrencesAction`, che sa non toccare il
 * passato e non scavalcare le date già modificate a mano.
 */
class OccurrencesRelationManager extends RelationManager
{
    protected static string $relationship = 'occurrences';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('manage.resources.occurrence.plural');
    }

    public function form(Schema $schema): Schema
    {
        $timezone = CurrentVenue::timezone();

        return $schema
            ->components([
                DateTimePicker::make('starts_at')
                    ->label(__('manage.fields.starts_at'))
                    ->seconds(false)
                    ->required()
                    ->timezone($timezone),

                DateTimePicker::make('ends_at')
                    ->label(__('manage.fields.ends_at'))
                    ->seconds(false)
                    ->after('starts_at')
                    ->timezone($timezone),

                /*
                 * Quanti posti restano, e su quanti. Il totale si chiede solo
                 * quando questa sera è diverso dal solito: senza un totale il
                 * conteggio resta un numero senza scala, e la card mostra il
                 * solo «posti rimasti» invece della barra.
                 */
                TextInput::make('capacity')
                    ->label(__('manage.fields.occurrence_capacity'))
                    ->helperText(__('manage.hints.occurrence_capacity'))
                    ->numeric()
                    ->minValue(0),

                TextInput::make('capacity_left')
                    ->label(__('manage.fields.occurrence_capacity_left'))
                    ->helperText(__('manage.hints.occurrence_capacity_left'))
                    ->numeric()
                    ->minValue(0),

                TextInput::make('highlight')
                    ->label(__('manage.fields.occurrence_highlight'))
                    ->helperText(__('manage.hints.occurrence_highlight'))
                    ->maxLength(40)
                    ->columnSpanFull(),

                Repeater::make('lineups')
                    ->label(__('manage.resources.lineup.plural'))
                    ->relationship()
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel(__('manage.actions.add_lineup'))
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label(__('manage.fields.artist'))
                            ->required()
                            ->maxLength(255),

                        Select::make('role')
                            ->label(__('manage.fields.artist_role'))
                            ->options(LineupRole::options())
                            ->required()
                            ->default(LineupRole::Live->value),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        $timezone = CurrentVenue::timezone();

        return $table
            ->recordTitleAttribute('starts_at')
            ->columns([
                TextColumn::make('starts_at')
                    ->label(__('manage.fields.starts_at'))
                    ->dateTime('d/m/Y H:i', $timezone)
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('manage.fields.occurrence_status'))
                    ->badge()
                    ->formatStateUsing(fn (OccurrenceStatus $state): string => $state->label())
                    ->color(fn (OccurrenceStatus $state): string => EventStatusPresentation::occurrenceColor($state)),

                TextColumn::make('capacity_left')
                    ->label(__('manage.fields.occurrence_capacity_left'))
                    ->placeholder(__('manage.placeholders.not_declared')),

                TextColumn::make('lineups_count')
                    ->label(__('manage.resources.lineup.plural'))
                    ->counts('lineups'),
            ])
            ->defaultSort('starts_at')
            ->headerActions([
                CreateAction::make()
                    ->label(__('manage.actions.add_date'))
                    ->authorize(fn (): bool => $this->canAddDate()),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('manage.actions.edit')),

                Action::make('cancel_occurrence')
                    ->label(__('manage.actions.cancel_date'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->authorize(fn (EventOccurrence $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->visible(fn (EventOccurrence $record): bool => $record->status !== OccurrenceStatus::Cancelled)
                    ->schema(fn (EventOccurrence $record): array => self::scopeField($record))
                    ->action(function (EventOccurrence $record, array $data): void {
                        self::notifyChanged(app(UpdateOccurrencesAction::class)->changeStatus(
                            $record,
                            OccurrenceStatus::Cancelled,
                            null,
                            self::scopeFrom($data),
                        ));
                    }),

                Action::make('sold_out')
                    ->label(fn (EventOccurrence $record): string => $record->status === OccurrenceStatus::SoldOut
                        ? __('manage.actions.mark_available')
                        : __('manage.actions.mark_sold_out'))
                    ->icon(Heroicon::OutlinedTicket)
                    ->requiresConfirmation()
                    ->authorize(fn (EventOccurrence $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(function (EventOccurrence $record): void {
                        self::notifyChanged(app(UpdateOccurrencesAction::class)->changeStatus(
                            $record,
                            $record->status === OccurrenceStatus::SoldOut
                                ? OccurrenceStatus::Scheduled
                                : OccurrenceStatus::SoldOut,
                            null,
                            OccurrenceScope::Single,
                        ));
                    }),

                DeleteAction::make(),
            ]);
    }

    /**
     * `EventOccurrencePolicy::create()` risponde "solo staff globale" quando
     * non riceve l'evento (D24, punto 2): qui la domanda va posta per esteso
     * — *può aggiungere date **a questo evento**?*
     */
    private function canAddDate(): bool
    {
        $event = $this->getOwnerRecord();

        return $event instanceof Event
            && (auth()->user()?->can('create', [EventOccurrence::class, $event]) ?? false);
    }

    /**
     * La scelta compare solo quando c'è davvero una serie da toccare.
     *
     * @return array<int, Radio>
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
                ? __('manage.notifications.nothing_to_do')
                : __('manage.notifications.dates_updated', ['count' => $changed]))
            ->success()
            ->send();
    }
}
