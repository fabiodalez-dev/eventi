<?php

namespace App\Filament\Organizer\Resources\Events;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Models\Event;
use App\Models\EventOccurrence;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DatesRelationManager extends RelationManager
{
    protected static string $relationship = 'occurrences';

    protected static ?string $title = 'Date e luoghi';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            DateTimePicker::make('starts_at')->label('Inizio')->required()->seconds(false)->timezone(fn () => $this->eventRecord()->city->timezone),
            DateTimePicker::make('ends_at')->label('Fine')->after('starts_at')->seconds(false)->timezone(fn () => $this->eventRecord()->city->timezone),
            Toggle::make('is_all_day')->label('Tutto il giorno'),
            Select::make('venue_id')->label('Locale di questa data')->relationship('venue', 'name', fn ($query) => $query->approved())->searchable()->placeholder('Usa il locale principale'),
            Select::make('status')->label('Stato')->options(OccurrenceStatus::options())->required()->default('scheduled'),
            Textarea::make('status_note')->label('Avviso al pubblico')->maxLength(2000),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('starts_at')->label('Data')->dateTime(), TextColumn::make('venue.name')->label('Locale')->placeholder('Locale principale'), TextColumn::make('status')->label('Stato')->badge()])
            ->headerActions([CreateAction::make()->authorize(fn () => auth()->user()?->can('create', [EventOccurrence::class, $this->getOwnerRecord()]))])
            ->recordActions([EditAction::make(),
                Action::make('tickets')->label('Prenotazioni')->url(fn ($record) => route('ticketing.manage.show', $record)),
                Action::make('social')->label('Grafica social')->url(fn ($record) => route('social.preview', $record))->openUrlInNewTab(),
                Action::make('poster')->label('Locandina PDF')->visible(fn () => in_array($this->eventRecord()->status, [EventStatus::Published, EventStatus::Archived], true))->url(fn ($record) => route('events.poster', ['slug' => $this->eventRecord()->slug, 'occurrence' => $record->url_number]))->openUrlInNewTab(),
            ]);
    }

    private function eventRecord(): Event
    {
        $record = $this->getOwnerRecord();
        if (! $record instanceof Event) {
            throw new \LogicException('Expected event');
        }

        return $record;
    }
}
