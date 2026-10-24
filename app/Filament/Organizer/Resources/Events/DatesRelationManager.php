<?php

namespace App\Filament\Organizer\Resources\Events;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Filament\Support\DescriptionEditor;
use App\Filament\Support\OccurrenceAnalyticsFields;
use App\Filament\Support\OccurrenceDecisionFields;
use App\Filament\Support\SameNightNearbyField;
use App\Filament\Support\VenueClosureWarning;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Venue;
use App\Services\Analytics\OccurrenceAnalytics;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
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
            ...OccurrenceDecisionFields::make(),
            DateTimePicker::make('starts_at')->label('Inizio')->required()->seconds(false)->live(onBlur: true)->timezone(fn () => $this->eventRecord()->city->timezone),
            DateTimePicker::make('ends_at')->label('Fine')->after('starts_at')->seconds(false)->live(onBlur: true)->timezone(fn () => $this->eventRecord()->city->timezone),
            Toggle::make('is_all_day')->label('Tutto il giorno'),
            Select::make('venue_id')->label('Locale di questa data')->relationship('venue', 'name', fn ($query) => $query->approved())->searchable()->live()->placeholder('Usa il locale principale'),
            SameNightNearbyField::make(fn (Get $get): ?array => $this->sameNightContext($get)),
            VenueClosureWarning::make(fn (Get $get): array => ['venue' => Venue::find($get('venue_id')) ?? $this->eventRecord()->venue, 'start' => SameNightNearbyField::time($get('starts_at'), 'UTC')]),
            Select::make('status')->label('Stato')->options(OccurrenceStatus::options())->required()->default('scheduled'),
            DescriptionEditor::make('status_note')->label('Avviso al pubblico')->maxLength(2000),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => OccurrenceAnalytics::withTotals($query))
            ->description(__('analytics.occurrence_totals'))
            ->columns([TextColumn::make('starts_at')->label('Data')->dateTime('d/m/Y H:i', $this->eventRecord()->city->timezone), TextColumn::make('venue.name')->label('Locale')->placeholder('Locale principale'), TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (OccurrenceStatus $state): string => $state->label()), ...OccurrenceAnalyticsFields::columns()])
            ->headerActions([CreateAction::make()->authorize(fn () => auth()->user()?->can('create', [EventOccurrence::class, $this->getOwnerRecord()]))])
            ->recordActions([EditAction::make(),
                OccurrenceAnalyticsFields::action(),
                Action::make('tickets')->label('Prenotazioni')->url(fn ($record) => route('ticketing.manage.show', $record)),
                Action::make('social')->label('Grafica social')->url(fn ($record) => route('social.preview', $record))->openUrlInNewTab(),
                Action::make('poster')->label('Locandina PDF')->visible(fn () => in_array($this->eventRecord()->status, [EventStatus::Published, EventStatus::Archived], true))->url(fn ($record) => route('events.poster', ['slug' => $this->eventRecord()->slug, 'occurrence' => $record->url_number]))->openUrlInNewTab(),
            ]);
    }

    /**
     * Il luogo è il locale scelto per questa data, poi l'indirizzo a mano
     * dell'evento, poi il locale principale. L'orario arriva in UTC.
     *
     * @return array{city: City, lat: float, lng: float, start: CarbonImmutable, end: CarbonImmutable|null, category_id: int|null, event_id: int}|null
     */
    private function sameNightContext(Get $get): ?array
    {
        $event = $this->eventRecord();
        $start = SameNightNearbyField::time($get('starts_at'), 'UTC');
        if ($start === null || $event->city === null) {
            return null;
        }
        $venue = Venue::find($get('venue_id'));
        $custom = $event->custom_location ?? [];
        [$lat, $lng] = match (true) {
            $venue !== null => [$venue->lat, $venue->lng],
            is_numeric($custom['lat'] ?? null) && is_numeric($custom['lng'] ?? null) => [$custom['lat'], $custom['lng']],
            default => [$event->venue?->lat, $event->venue?->lng],
        };
        if ($lat === null || $lng === null) {
            return null;
        }

        return ['city' => $event->city, 'lat' => (float) $lat, 'lng' => (float) $lng, 'start' => $start,
            'end' => SameNightNearbyField::time($get('ends_at'), 'UTC'),
            'category_id' => (int) $event->category_id, 'event_id' => (int) $event->id];
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
