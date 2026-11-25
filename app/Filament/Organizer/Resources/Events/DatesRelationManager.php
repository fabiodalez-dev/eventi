<?php

namespace App\Filament\Organizer\Resources\Events;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Filament\Support\DescriptionEditor;
use App\Filament\Support\OccurrenceAnalyticsFields;
use App\Filament\Support\OccurrenceDecisionFields;
use App\Filament\Support\SameNightNearbyField;
use App\Filament\Support\VenueClosureWarning;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Analytics\ManagementAnalytics;
use App\Services\Analytics\OccurrenceAnalytics;
use App\Services\Events\OrganizerEventEditor;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
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
            VenueClosureWarning::make(fn (Get $get): array => ['venue' => Venue::find($get('venue_id')) ?? (filled($this->eventRecord()->custom_location['address'] ?? null) ? null : $this->eventRecord()->venue), 'start' => SameNightNearbyField::time($get('starts_at'), 'UTC')]),
            Select::make('status')->label('Stato')->options(OccurrenceStatus::options())->required()->default('scheduled'),
            DescriptionEditor::make('status_note')->label('Avviso al pubblico')->maxLength(2000),
        ]);
    }

    public function table(Table $table): Table
    {
        /* Visite, clic, salvataggi e l'azione «Statistiche» sono
           dell'organizzatore principale (`ManagementAnalytics::covers`): su un
           evento soltanto co-organizzato i numeri sono di chi lo organizza,
           come in «Statistiche» e nell'app. */
        $analytics = $this->showsAnalytics();

        return $table->modifyQueryUsing(fn ($query) => $analytics ? OccurrenceAnalytics::withTotals($query) : $query)
            ->description($analytics ? __('analytics.occurrence_totals') : null)
            ->columns([TextColumn::make('starts_at')->label('Data')->dateTime('d/m/Y H:i', $this->eventRecord()->city->timezone), TextColumn::make('venue.name')->label('Locale')->placeholder('Locale principale'), TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (OccurrenceStatus $state): string => $state->label()), ...($analytics ? OccurrenceAnalyticsFields::columns() : [])])
            ->headerActions([CreateAction::make()->authorize(fn () => auth()->user()?->can('create', [EventOccurrence::class, $this->getOwnerRecord()]))
                ->after(fn () => $this->backToReview(true))])
            ->recordActions([EditAction::make()->after(fn (EventOccurrence $record) => $this->backToReview($record->wasChanged('venue_id'))),
                OccurrenceAnalyticsFields::action()->visible($analytics),
                Action::make('tickets')->label('Prenotazioni')->url(fn ($record) => route('ticketing.manage.show', $record)),
                Action::make('social')->label('Grafica social')->url(fn ($record) => route('social.preview', $record))->openUrlInNewTab(),
                Action::make('poster')->label('Locandina PDF')->visible(fn () => in_array($this->eventRecord()->status, [EventStatus::Published, EventStatus::Archived], true))->url(fn ($record) => route('events.poster', ['slug' => $this->eventRecord()->slug, 'occurrence' => $record->url_number]))->openUrlInNewTab(),
            ]);
    }

    /**
     * Il luogo è il locale scelto per questa data, poi l'indirizzo a mano
     * dell'evento, poi il locale principale. L'orario arriva in UTC.
     *
     * @return array<string, mixed>|null
     */
    private function sameNightContext(Get $get): ?array
    {
        $event = $this->eventRecord();

        return SameNightNearbyField::context($event->city, [Venue::find($get('venue_id')), $event->custom_location ?? [], $event->venue], $get('starts_at'), $get('ends_at'), 'UTC', categoryId: $event->category_id, eventId: (int) $event->id);
    }

    /**
     * Una data nuova, o portata in un altro locale, su un evento già online
     * torna dalla redazione: la regola sta in `OrganizerEventEditor`, la
     * stessa che usa l'app.
     */
    private function backToReview(bool $changed): void
    {
        $user = auth()->user();
        if ($user instanceof User) {
            app(OrganizerEventEditor::class)->afterDateSaved($this->eventRecord(), $user, $changed);
        }
    }

    private function showsAnalytics(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organizer && ManagementAnalytics::covers($tenant, $this->eventRecord());
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
