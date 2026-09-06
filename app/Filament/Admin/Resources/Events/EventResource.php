<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Events;

use App\Enums\EventSource;
use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Enums\VerificationStatus;
use App\Filament\Admin\Resources\Events\Pages\CreateEvent;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Events\RelationManagers\ActivityRelationManager;
use App\Filament\Admin\Resources\Events\RelationManagers\OccurrencesRelationManager;
use App\Filament\Admin\Support\StructuredFields;
use App\Filament\Forms\Components\MapPicker;
use App\Filament\Support\EventStatusPresentation;
use App\Filament\Support\ExternalLinksField;
use App\Filament\Support\FactsField;
use App\Filament\Support\ImageUpload;
use App\Filament\Support\SharedEventTags;
use App\Filament\Support\TicketTiersField;
use App\Models\City;
use App\Models\Event;
use App\Queries\EditorialDashboardQuery;
use App\Support\CurrentCity;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * §9.2 — l'evento: la scheda editoriale, non una data.
 *
 * **Le date stanno in due posti diversi, e non è una svista.**
 *
 * - Alla **creazione** c'è il ripetitore delle occorrenze: si inserisce
 *   l'evento con le sue tre serate in un solo salvataggio, che è il gesto per
 *   cui il ripetitore esiste.
 * - Alla **modifica** le date passano alla sezione dedicata, dove ogni riga
 *   porta con sé la domanda che il ripetitore non può porre: *questa data
 *   soltanto, o tutta la serie?* (§9.2).
 *
 * Tenerli entrambi attivi significherebbe avere due moduli che scrivono le
 * stesse righe nella stessa pagina: chi salva il modulo principale
 * sovrascriverebbe con lo stato caricato in apertura quanto appena deciso
 * nella sezione delle date.
 *
 * **Le colonne calcolate non compaiono nel modulo.** `business_date` ed
 * `effective_ends_at` le scrive `EventOccurrenceObserver` (§5 delle
 * convenzioni): si leggono nella tabella delle date, non si compilano.
 */
class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.content');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.event.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.event.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Event::query()->where('status', EventStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)
            ->components([
                /*
                 * **Schede, non dodici riquadri su due colonne.**
                 *
                 * E' la pagina piu' usata del pannello e ne aveva dodici, con
                 * la stessa griglia che allineava le righe all'elemento piu'
                 * alto: sezioni corte accanto a sezioni lunghe, e il vuoto in
                 * mezzo. Ora ogni gruppo sta a piena larghezza dentro la
                 * propria scheda, e «Pubblicazione» — che si cerca ogni volta
                 * — e' a un clic invece che in fondo allo scorrimento.
                 */
                Tabs::make('form_tabs')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make(__('admin.form_tabs.what'))
                            ->schema([
                                Section::make(__('admin.sections.general'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('title')
                                            ->label(__('admin.fields.title'))
                                            ->required()
                                            ->maxLength(255)
                                            ->columnSpanFull(),

                                        TextInput::make('subtitle')
                                            ->label(__('admin.fields.subtitle'))
                                            ->maxLength(255),

                                        TextInput::make('slug')
                                            ->label(__('admin.fields.slug'))
                                            ->maxLength(255),

                                        Textarea::make('short_description')
                                            ->label(__('admin.fields.short_description'))
                                            ->helperText(__('admin.hints.short_description'))
                                            ->maxLength(500)
                                            ->rows(2)
                                            ->columnSpanFull(),

                                        Textarea::make('description')
                                            ->label(__('admin.fields.description'))
                                            ->rows(8)
                                            ->columnSpanFull(),
                                    ]),

                                Section::make(__('admin.sections.taxonomy'))
                                    ->columns(2)
                                    ->schema([
                                        Select::make('category_id')
                                            ->label(__('admin.fields.category'))
                                            ->relationship('category', 'name')
                                            ->required()
                                            ->searchable()
                                            ->preload(),

                                        SharedEventTags::make(),

                                        TextInput::make('age_restriction')
                                            ->label(__('admin.fields.age_restriction'))
                                            ->maxLength(40),

                                        TextInput::make('language')
                                            ->label(__('admin.fields.language'))
                                            ->maxLength(5),
                                    ]),

                                Section::make(__('admin.sections.media'))
                                    ->columns(2)
                                    ->schema([
                                        ImageUpload::make('poster_media')
                                            ->label(__('admin.fields.poster'))
                                            ->helperText(__('admin.hints.poster'))
                                            ->collection('poster')
                                            ->imageEditor(),

                                        ImageUpload::make('gallery_media')
                                            ->label(__('admin.fields.gallery'))
                                            ->collection('gallery')
                                            ->multiple()
                                            ->reorderable(),
                                    ]),
                            ]),

                        Tab::make(__('admin.form_tabs.when'))
                            ->schema([
                                Section::make(__('admin.sections.when'))
                                    ->description(__('admin.hints.occurrences'))
                                    ->visible(fn (string $operation): bool => $operation === 'create')
                                    ->schema([
                                        Repeater::make('occurrences')
                                            ->label(__('admin.resources.occurrence.plural'))
                                            ->relationship()
                                            ->columns(['default' => 1, 'lg' => 2])
                                            ->defaultItems(1)
                                            ->minItems(1)
                                            ->addActionLabel(__('admin.actions.add_occurrence'))
                                            ->schema([
                                                DateTimePicker::make('starts_at')
                                                    ->label(__('admin.fields.starts_at'))
                                                    ->seconds(false)
                                                    ->timezone(fn (Get $get): string => self::cityTimezone($get('../../city_id')))
                                                    ->helperText(fn (Get $get): string => self::timezoneHint($get('../../city_id')))
                                                    ->required(),

                                                DateTimePicker::make('ends_at')
                                                    ->label(__('admin.fields.ends_at'))
                                                    ->seconds(false)
                                                    ->timezone(fn (Get $get): string => self::cityTimezone($get('../../city_id')))
                                                    ->after('starts_at'),

                                                DateTimePicker::make('doors_at')
                                                    ->label(__('admin.fields.doors_at'))
                                                    ->seconds(false)
                                                    ->timezone(fn (Get $get): string => self::cityTimezone($get('../../city_id'))),

                                                Toggle::make('is_all_day')
                                                    ->label(__('admin.fields.is_all_day'))
                                                    ->inline(false),
                                            ]),
                                    ]),

                                Section::make(__('admin.sections.recurrence'))
                                    ->schema([
                                        Repeater::make('recurrences')
                                            ->label(__('admin.resources.recurrence.plural'))
                                            ->relationship()
                                            ->columns(2)
                                            ->defaultItems(0)
                                            ->addActionLabel(__('admin.actions.add_recurrence'))
                                            ->schema([
                                                TextInput::make('rrule')
                                                    ->label(__('admin.fields.rrule'))
                                                    ->helperText(__('admin.hints.rrule'))
                                                    ->required()
                                                    ->maxLength(500)
                                                    ->columnSpanFull(),

                                                DateTimePicker::make('until')
                                                    ->label(__('admin.fields.until'))
                                                    ->seconds(false)
                                                    ->timezone(fn (Get $get): string => self::cityTimezone($get('../../city_id'))),

                                                DateTimePicker::make('generated_until')
                                                    ->label(__('admin.fields.generated_until'))
                                                    ->seconds(false)
                                                    ->disabled()
                                                    ->dehydrated(false),

                                                Textarea::make('exdates')
                                                    ->label(__('admin.fields.exdates'))
                                                    ->helperText(__('admin.hints.exdates'))
                                                    ->rows(3)
                                                    ->columnSpanFull()
                                                    // Una data per riga nel modulo, un elenco
                                                    // JSON sul database: la conversione sta in
                                                    // `StructuredFields`, che è anche l'unico
                                                    // punto in cui i due formati si incontrano.
                                                    ->formatStateUsing(StructuredFields::datesToLines(...))
                                                    ->dehydrateStateUsing(StructuredFields::linesToDates(...)),
                                            ]),
                                    ]),
                            ]),

                        Tab::make(__('admin.form_tabs.where'))
                            ->schema([
                                Section::make(__('admin.sections.where'))
                                    ->columns(2)
                                    ->schema([
                                        Select::make('city_id')
                                            ->label(__('admin.fields.city'))
                                            ->relationship('city', 'name')
                                            ->required()
                                            ->searchable()
                                            ->preload()
                                            ->live()
                                            ->default(fn (): ?int => self::defaultCityId()),

                                        Select::make('venue_id')
                                            ->label(__('admin.fields.venue'))
                                            ->relationship(
                                                'venue',
                                                'name',
                                                fn (Builder $query, Get $get) => $query
                                                    ->when($get('city_id'), fn (Builder $q, $city) => $q->where('city_id', $city))
                                                    ->orderBy('name'),
                                            )
                                            ->searchable()
                                            ->preload(),

                                        /*
                                         * **Il luogo che non e' un locale.**
                                         *
                                         * Una piazza, un parco, una via
                                         * chiusa per una festa: esiste sul
                                         * territorio ma non ha una scheda, e
                                         * per questo `custom_location` e' un
                                         * campo JSON.
                                         *
                                         * Era una tabella chiave-valore: si
                                         * scriveva a mano `name`, `address`,
                                         * `lat`, `lng` — i nomi delle chiavi
                                         * compresi. Chi non li sapeva a
                                         * memoria inventava (`nome`,
                                         * `indirizzo`) e il sito non leggeva
                                         * piu' niente, senza che nessun
                                         * errore lo dicesse: le viste
                                         * cercano `name` e trovano `null`.
                                         *
                                         * Ora sono campi con un nome scritto
                                         * in italiano e una mappa. Le chiavi
                                         * del JSON restano quelle che il sito
                                         * legge, ma non le sceglie piu'
                                         * nessuno a mano.
                                         */
                                        Fieldset::make(__('admin.fields.custom_location'))
                                            ->columnSpanFull()
                                            ->columns(2)
                                            ->visible(fn (Get $get): bool => blank($get('venue_id')))
                                            ->schema([
                                                TextInput::make('custom_location.name')
                                                    ->label(__('admin.fields.custom_location_name'))
                                                    ->maxLength(255),

                                                TextInput::make('custom_location.address')
                                                    ->label(__('admin.fields.address'))
                                                    ->maxLength(255),

                                                MapPicker::make('mappa_luogo')
                                                    ->label(__('admin.fields.map'))
                                                    ->columnSpanFull()
                                                    ->coordinateFields('custom_location.lat', 'custom_location.lng')
                                                    ->addressFields('custom_location.address'),

                                                TextInput::make('custom_location.lat')
                                                    ->label(__('admin.fields.lat'))
                                                    ->numeric(),

                                                TextInput::make('custom_location.lng')
                                                    ->label(__('admin.fields.lng'))
                                                    ->numeric(),
                                            ]),

                                        Toggle::make('is_outdoor')
                                            ->label(__('admin.fields.is_outdoor')),
                                    ]),

                                Section::make(__('admin.sections.organizer'))
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('organizer_name')
                                            ->label(__('admin.fields.organizer_name'))
                                            ->maxLength(255),

                                        TextInput::make('organizer_url')
                                            ->label(__('admin.fields.organizer_url'))
                                            ->url()
                                            ->maxLength(255),
                                    ]),
                            ]),

                        Tab::make(__('admin.form_tabs.tickets'))
                            ->schema([
                                Section::make(__('admin.sections.price'))
                                    ->columns(['default' => 1, 'lg' => 2])
                                    ->schema([
                                        Select::make('price_type')
                                            ->label(__('admin.fields.price_type'))
                                            ->options(PriceType::options())
                                            ->required()
                                            ->default(PriceType::Unknown->value)
                                            ->live(),

                                        TextInput::make('price_min')
                                            ->label(__('admin.fields.price_min'))
                                            ->numeric()
                                            ->minValue(0),

                                        TextInput::make('price_max')
                                            ->label(__('admin.fields.price_max'))
                                            ->numeric()
                                            ->minValue(0),

                                        TextInput::make('price_notes')
                                            ->label(__('admin.fields.price_notes'))
                                            ->maxLength(255)
                                            ->columnSpan(['default' => 1, 'lg' => 2]),

                                        TextInput::make('currency')
                                            ->label(__('admin.fields.currency'))
                                            ->required()
                                            ->default('EUR')
                                            ->maxLength(3),

                                        TextInput::make('ticket_url')
                                            ->label(__('admin.fields.ticket_url'))
                                            ->url()
                                            ->maxLength(255)
                                            ->columnSpan(['default' => 1, 'lg' => 2]),

                                        Toggle::make('booking_required')
                                            ->label(__('admin.fields.booking_required'))
                                            ->live(),

                                        TextInput::make('booking_url')
                                            ->label(__('admin.fields.booking_url'))
                                            ->url()
                                            ->maxLength(255)
                                            ->visible(fn (Get $get): bool => (bool) $get('booking_required')),

                                        TextInput::make('booking_phone')
                                            ->label(__('admin.fields.booking_phone'))
                                            ->tel()
                                            ->maxLength(40)
                                            ->visible(fn (Get $get): bool => (bool) $get('booking_required')),
                                    ]),

                                /*
                         * Il listino dell'evento. Lo stato è **per fascia**: è la sola
                         * forma in cui «parterre esaurito, secondo anello disponibile»
                         * si può dire — `OccurrenceStatus::SoldOut` marca l'intera
                         * serata e non lo sa fare.
                         */
                                Section::make(__('admin.sections.ticket_tiers'))
                                    ->schema([
                                        TicketTiersField::make('admin'),
                                    ]),
                            ]),

                        Tab::make(__('admin.form_tabs.extra'))
                            ->schema([
                                Section::make(__('admin.sections.facts'))
                                    ->schema([
                                        FactsField::make('admin', 'facts'),
                                    ]),

                                Section::make(__('admin.sections.external_links'))
                                    ->schema([
                                        ExternalLinksField::make('admin'),
                                    ]),
                            ]),

                        Tab::make(__('admin.form_tabs.publish'))
                            ->schema([
                                Section::make(__('admin.sections.publication'))
                                    ->columns(['default' => 1, 'lg' => 2])
                                    ->schema([
                                        ToggleButtons::make('status')
                                            ->label(__('admin.fields.status'))
                                            ->options(EventStatus::options())
                                            ->inline()
                                            ->required()
                                            ->default(EventStatus::Draft->value)
                                            ->columnSpanFull(),

                                        Select::make('source')
                                            ->label(__('admin.fields.source'))
                                            ->options(EventSource::options())
                                            ->required()
                                            ->default(EventSource::Manual->value),

                                        Select::make('verification_status')
                                            ->label(__('admin.fields.verification_status'))
                                            ->options(VerificationStatus::options())
                                            ->required()
                                            ->default(VerificationStatus::Unverified->value),

                                        TextInput::make('editorial_score')
                                            ->label(__('admin.fields.editorial_score'))
                                            ->helperText(__('admin.hints.editorial_score'))
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(100)
                                            ->default(0),

                                        Toggle::make('is_featured')
                                            ->label(__('admin.fields.is_featured'))
                                            ->live(),

                                        DateTimePicker::make('featured_until')
                                            ->label(__('admin.fields.featured_until'))
                                            ->seconds(false)
                                            ->visible(fn (Get $get): bool => (bool) $get('is_featured')),

                                        Textarea::make('rejection_reason')
                                            ->label(__('admin.fields.rejection_reason'))
                                            ->rows(2)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.fields.title'))
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    /*
                     * Da dove arriva questo evento.
                     *
                     * Nel sito pubblico un evento importato ha la stessa
                     * dignita di uno inserito a mano: chi cerca cosa fare
                     * stasera non deve chiedersi da quale tubo sia passato.
                     * Qui dentro no: chi modera deve riconoscere a colpo
                     * d'occhio cio che nessuno ha letto prima di pubblicarlo,
                     * ed e la meta operativa di §14.1.
                     *
                     * Sta sotto al titolo e non in una colonna propria perche
                     * la stragrande maggioranza degli eventi e inserita a mano:
                     * una colonna quasi sempre vuota ruba spazio a quelle che
                     * si leggono davvero.
                     */
                    ->description(fn (Event $record): ?string => $record->source->isImported()
                        ? $record->source->label()
                        : null)
                    ->icon(fn (Event $record): ?Heroicon => $record->source->isImported()
                        ? Heroicon::OutlinedArrowDownTray
                        : null)
                    ->iconPosition(IconPosition::After)
                    ->iconColor('gray'),

                TextColumn::make('venue.name')
                    ->label(__('admin.fields.venue'))
                    ->searchable()
                    ->placeholder(__('admin.placeholders.none')),

                TextColumn::make('category.name')
                    ->label(__('admin.fields.category')),

                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (EventStatus $state): string => $state->label())
                    ->color(fn (EventStatus $state): string => EventStatusPresentation::color($state)),

                TextColumn::make('occurrences_count')
                    ->label(__('admin.fields.occurrences_count'))
                    ->counts('occurrences'),

                TextColumn::make('published_at')
                    ->label(__('admin.fields.published_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('admin.placeholders.never'))
                    ->sortable(),

                IconColumn::make('is_featured')
                    ->label(__('admin.fields.is_featured'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(EventStatus::options()),

                SelectFilter::make('city')
                    ->label(__('admin.fields.city'))
                    ->relationship('city', 'name'),

                SelectFilter::make('venue')
                    ->label(__('admin.fields.venue'))
                    ->relationship('venue', 'name')
                    ->searchable(),

                SelectFilter::make('category')
                    ->label(__('admin.fields.category'))
                    ->relationship('category', 'name'),

                SelectFilter::make('source')
                    ->label(__('admin.fields.source'))
                    ->options(EventSource::options()),

                TernaryFilter::make('is_featured')
                    ->label(__('admin.fields.is_featured')),

                Filter::make('published_today')
                    ->label(__('admin.dashboard.published_today'))
                    ->query(fn (Builder $query) => self::dashboard()->applyPublishedToday($query)),

                Filter::make('published_this_week')
                    ->label(__('admin.dashboard.published_this_week'))
                    ->query(fn (Builder $query) => self::dashboard()->applyPublishedThisWeek($query)),

                Filter::make('missing_poster')
                    ->label(__('admin.dashboard.missing_poster'))
                    ->query(fn (Builder $query) => EditorialDashboardQuery::missingPosterScope($query)),

                Filter::make('incomplete')
                    ->label(__('admin.dashboard.incomplete'))
                    ->query(fn (Builder $query) => EditorialDashboardQuery::incompleteScope($query)),

                Filter::make('possible_duplicates')
                    ->label(__('admin.dashboard.possible_duplicates'))
                    ->query(fn (Builder $query) => self::dashboard()->applyPossibleDuplicates($query)),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            OccurrencesRelationManager::class,
            ActivityRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/create'),
            'edit' => EditEvent::route('/{record}/edit'),
        ];
    }

    public static function defaultCityId(): ?int
    {
        $city = app(CurrentCity::class)->get() ?? City::query()->orderBy('id')->first();

        return $city?->getKey();
    }

    private static function dashboard(): EditorialDashboardQuery
    {
        $city = app(CurrentCity::class)->get() ?? City::query()->orderBy('id')->firstOrFail();

        return EditorialDashboardQuery::for($city);
    }

    /**
     * Fuso della citta a cui appartiene l'evento in lavorazione.
     *
     * I DateTimePicker devono mostrare e accettare l'ora LOCALE della citta.
     * Senza questo, chi scrive "21:30" nel pannello salva 21:30 UTC, cioe le
     * 23:30 a Padova: due ore di errore che poi si propagano in tutto il motore
     * temporale, business_date compresa.
     */
    protected static function cityTimezone(mixed $cityId): string
    {
        $fallback = app(CurrentCity::class)->timezone();

        if (blank($cityId)) {
            return $fallback;
        }

        return City::query()->whereKey($cityId)->value('timezone') ?? $fallback;
    }

    /**
     * Nota sotto il campo, cosi chi inserisce sa in quale fuso sta scrivendo.
     */
    protected static function timezoneHint(mixed $cityId): string
    {
        return __('admin.hints.local_time', ['timezone' => self::cityTimezone($cityId)]);
    }
}
