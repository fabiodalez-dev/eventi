<?php

declare(strict_types=1);

namespace App\Filament\Venue\Resources\Events;

use App\Enums\EventStatus;
use App\Filament\Support\EventStatusPresentation;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Filament\Venue\Resources\Events\Pages\ListEvents;
use App\Filament\Venue\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Filament\Venue\Support\CurrentVenue;
use App\Filament\Venue\Support\EventActions;
use App\Filament\Venue\Support\EventFields;
use App\Models\Event;
use App\Queries\VenueDashboardQuery;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gli eventi del locale (§10).
 *
 * **Tenancy.** La risorsa è agganciata a `Venue` attraverso la relazione
 * `venue` dell'evento: Filament aggiunge da sé il filtro sul locale corrente a
 * ogni query e associa il locale a ogni riga creata. Non è però l'unica
 * difesa, e non è nemmeno la principale — `EventPolicy` verifica il
 * `venue_id` riga per riga, e nemmeno un ID scritto a mano nell'indirizzo
 * apre la scheda di un altro locale (§18 scenario F).
 *
 * **`canCreate()` è riscritto di proposito.** `EventPolicy::create()` accetta
 * il locale come secondo argomento e senza di esso risponde "solo staff
 * globale" (D24, punto 2). Filament chiama l'abilità con la sola classe, e la
 * risposta sarebbe no per chiunque gestisca un locale: qui la domanda viene
 * riformulata come va posta in questo pannello — *può creare eventi **per
 * questo locale**?*
 *
 * **La tabella è pensata per un pollice.** Tre informazioni per riga —
 * locandina, titolo con la prossima data, stato — impilate in verticale sul
 * telefono, affiancate sullo schermo grande. Niente colonne che costringano
 * la pagina a scorrere di lato.
 *
 * @extends resource<Event>
 */
class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static ?string $slug = 'eventi';

    protected static ?string $tenantOwnershipRelationshipName = 'venue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getModelLabel(): string
    {
        return __('manage.resources.event.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('manage.resources.event.plural');
    }

    /**
     * La voce di menu si scrive come si scrive in italiano. Senza questo
     * metodo Filament passa l'etichetta plurale per una funzione di
     * capitalizzazione pensata per l'inglese, e «I tuoi eventi» diventa
     * «I Tuoi Eventi».
     */
    public static function getNavigationLabel(): string
    {
        return __('manage.resources.event.plural');
    }

    /**
     * Il permesso di creare, riformulato come va posto in questo pannello.
     *
     * `EventPolicy::create()` accetta il locale come secondo argomento e senza
     * di esso risponde "solo staff globale" (D24, punto 2). Filament chiede
     * l'abilità con la sola classe, quindi la risposta sarebbe no per chiunque
     * gestisca un locale — e il pulsante "Nuovo evento" sparirebbe proprio a
     * chi il pannello è dedicato.
     *
     * Si riscrive **la risposta di autorizzazione**, non `canCreate()`: è da
     * questo metodo che passano tutti e due i controlli, quello del pulsante
     * (`CreateAction`) e quello della pagina (`CreateRecord::authorizeAccess()`).
     * Riscrivere solo `canCreate()` lascerebbe il pulsante invisibile e la
     * pagina raggiungibile — cioè il difetto peggiore dei due.
     */
    public static function getCreateAuthorizationResponse(): Response
    {
        return auth()->user()?->can('create', [Event::class, CurrentVenue::get()]) === true
            ? Response::allow()
            : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('manage.sections.what'))
                    ->schema([
                        EventFields::poster(),
                        EventFields::title(),
                        EventFields::description(),
                    ]),

                Section::make(__('manage.sections.taxonomy'))
                    ->columns(2)
                    ->schema([
                        EventFields::category(),
                        EventFields::tags(),
                    ]),

                Section::make(__('manage.sections.price'))
                    ->columns(2)
                    ->schema([
                        EventFields::priceType()->columnSpanFull(),
                        EventFields::priceMin(),
                        EventFields::priceMax(),
                    ]),

                // Gli stessi campi dell'ultimo passo del wizard (§10.2), nello
                // stesso ordine: chi ha appena creato l'evento ritrova qui
                // quello che ha visto un minuto fa.
                Section::make(__('manage.sections.links'))
                    ->schema([
                        EventFields::ticketUrl(),
                        EventFields::bookingUrl(),
                        EventFields::externalLinks(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Split::make([
                    SpatieMediaLibraryImageColumn::make('poster_media')
                        ->label(__('manage.fields.poster'))
                        ->collection('poster')
                        ->imageSize(56)
                        ->grow(false),

                    Stack::make([
                        TextColumn::make('title')
                            ->label(__('manage.fields.title'))
                            ->searchable()
                            ->weight('bold'),

                        TextColumn::make('next_starts_at')
                            ->label(__('manage.fields.next_date'))
                            ->dateTime('d/m/Y H:i', CurrentVenue::timezone())
                            ->icon(Heroicon::OutlinedClock)
                            ->placeholder(__('manage.placeholders.no_date')),
                    ]),

                    TextColumn::make('status')
                        ->label(__('manage.fields.status'))
                        ->badge()
                        ->formatStateUsing(fn (EventStatus $state): string => $state->label())
                        ->color(fn (EventStatus $state): string => EventStatusPresentation::color($state))
                        ->grow(false),
                ])->from('md'),
            ])
            ->defaultSort('next_starts_at', 'asc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('manage.fields.status'))
                    ->options(EventStatus::options()),
            ])
            ->recordActions([
                EditAction::make()->label(__('manage.actions.edit')),
                EventActions::viewOnSite(),
                EventActions::duplicate(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        VenueDashboardQuery::applyNextOccurrence($query, CurrentVenue::city());

        return $query;
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            OccurrencesRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/nuovo'),
            'edit' => EditEvent::route('/{record}/modifica'),
        ];
    }
}
