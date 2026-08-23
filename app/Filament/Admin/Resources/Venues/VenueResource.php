<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Venues;

use App\Enums\VenuePlan;
use App\Enums\VenueStatus;
use App\Enums\VenueType;
use App\Filament\Admin\Resources\Venues\Pages\CreateVenue;
use App\Filament\Admin\Resources\Venues\Pages\EditVenue;
use App\Filament\Admin\Resources\Venues\Pages\ListVenues;
use App\Filament\Admin\Resources\Venues\RelationManagers\EventsRelationManager;
use App\Filament\Admin\Resources\Venues\RelationManagers\MembersRelationManager;
use App\Filament\Admin\Support\VenueModeration;
use App\Models\City;
use App\Models\Venue;
use App\Queries\EditorialDashboardQuery;
use App\Support\WidgetEmbed;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * §9.2 — i locali. CRUD, moderazione, collaboratori, eventi collegati.
 *
 * **La mappa.** Il piano chiede un marcatore trascinabile; qui il punto si
 * scrive come coppia latitudine/longitudine con un'anteprima e una scorciatoia
 * che parte dal centro della città (D25). Il valore geometrico su cui gira
 * l'indice spaziale non viene mai compilato a mano: lo deriva
 * `VenueObserver` da `lat` e `lng`.
 *
 * **Le azioni di moderazione** (approva, rifiuta, sospendi, verifica) vivono
 * in `VenueModeration` perché servono identiche alla lista e alla scheda, e
 * chiedono ciascuna il permesso `moderate` alla Policy.
 */
class VenueResource extends Resource
{
    protected static ?string $model = Venue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.places');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.venue.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.venue.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = Venue::query()->where('status', VenueStatus::Pending)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.sections.general'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('admin.fields.name'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('slug')
                            ->label(__('admin.fields.slug'))
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        Select::make('city_id')
                            ->label(__('admin.fields.city'))
                            ->relationship('city', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => City::query()->orderBy('id')->value('id')),

                        Select::make('type')
                            ->label(__('admin.fields.type'))
                            ->options(VenueType::options())
                            ->required()
                            ->default(VenueType::Altro->value),

                        Textarea::make('short_description')
                            ->label(__('admin.fields.short_description'))
                            ->helperText(__('admin.hints.short_description'))
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull(),

                        Textarea::make('description')
                            ->label(__('admin.fields.description'))
                            ->rows(6)
                            ->columnSpanFull(),
                    ]),

                Section::make(__('admin.sections.where'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('address')
                            ->label(__('admin.fields.address'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        TextInput::make('address_extra')
                            ->label(__('admin.fields.address_extra'))
                            ->maxLength(255),

                        TextInput::make('municipality')
                            ->label(__('admin.fields.municipality'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('postal_code')
                            ->label(__('admin.fields.postal_code'))
                            ->maxLength(10),

                        TextInput::make('province_code')
                            ->label(__('admin.fields.province_code'))
                            ->required()
                            ->maxLength(4),

                        TextInput::make('lat')
                            ->label(__('admin.fields.lat'))
                            ->required()
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90)
                            ->live(onBlur: true),

                        TextInput::make('lng')
                            ->label(__('admin.fields.lng'))
                            ->required()
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180)
                            ->live(onBlur: true),

                        Text::make(fn (Get $get): HtmlString => self::coordinatesPreview($get))
                            ->columnSpanFull(),
                    ])
                    ->footerActions([
                        Action::make('center_on_city')
                            ->label(__('admin.actions.center_on_city'))
                            ->icon(Heroicon::OutlinedMapPin)
                            ->action(function (Get $get, Set $set): void {
                                $city = City::query()->find($get('city_id'));

                                if ($city === null) {
                                    return;
                                }

                                $set('lat', (float) $city->center_lat);
                                $set('lng', (float) $city->center_lng);
                            }),
                    ]),

                Section::make(__('admin.sections.contacts'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('phone')
                            ->label(__('admin.fields.phone'))
                            ->tel()
                            ->maxLength(40),

                        TextInput::make('email')
                            ->label(__('admin.fields.email'))
                            ->email()
                            ->maxLength(255),

                        TextInput::make('website')
                            ->label(__('admin.fields.website'))
                            ->url()
                            ->maxLength(255),

                        KeyValue::make('socials')
                            ->label(__('admin.fields.socials'))
                            ->columnSpanFull(),
                    ]),

                Section::make(__('admin.sections.advanced'))
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TextInput::make('capacity')
                            ->label(__('admin.fields.capacity'))
                            ->numeric()
                            ->minValue(0),

                        Toggle::make('requires_membership')
                            ->label(__('admin.fields.requires_membership')),

                        Textarea::make('membership_notes')
                            ->label(__('admin.fields.membership_notes'))
                            ->rows(2),

                        KeyValue::make('accessibility')
                            ->label(__('admin.fields.accessibility'))
                            ->columnSpanFull(),

                        Repeater::make('opening_hours')
                            ->label(__('admin.fields.opening_hours'))
                            ->columnSpanFull()
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel(__('admin.actions.add_opening_hours'))
                            ->schema([
                                Select::make('day')
                                    ->label(__('admin.fields.opening_day'))
                                    ->options(self::weekdays())
                                    ->required(),

                                TimePicker::make('open')
                                    ->label(__('admin.fields.opening_from'))
                                    ->seconds(false)
                                    ->required(),

                                TimePicker::make('close')
                                    ->label(__('admin.fields.opening_to'))
                                    ->seconds(false)
                                    ->required(),
                            ]),
                    ]),

                Section::make(__('admin.sections.media'))
                    ->columns(3)
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('logo')
                            ->label(__('admin.fields.logo'))
                            ->collection('logo')
                            ->image()
                            ->imageEditor(),

                        SpatieMediaLibraryFileUpload::make('cover')
                            ->label(__('admin.fields.cover'))
                            ->collection('cover')
                            ->image()
                            ->imageEditor(),

                        SpatieMediaLibraryFileUpload::make('gallery')
                            ->label(__('admin.fields.gallery'))
                            ->collection('gallery')
                            ->image()
                            ->multiple()
                            ->reorderable(),
                    ]),

                /*
                 * Il codice del widget incorporabile (§11.10). È di sola
                 * lettura e non viene salvato: si calcola dallo slug del
                 * locale, e leggerlo qui è il gesto per cui esiste — copiarlo
                 * e incollarlo nel proprio sito.
                 */
                Section::make(__('venues.widget.title'))
                    ->description(__('venues.widget.lead'))
                    ->visibleOn('edit')
                    ->schema([
                        Textarea::make('widget_snippet')
                            ->label(__('venues.widget.snippet_label'))
                            ->rows(3)
                            ->readOnly()
                            ->dehydrated(false)
                            ->formatStateUsing(fn (?Venue $record): string => $record === null ? '' : WidgetEmbed::snippet($record)),
                    ]),

                Section::make(__('admin.sections.moderation'))
                    ->columns(3)
                    ->schema([
                        ToggleButtons::make('status')
                            ->label(__('admin.fields.status'))
                            ->options(VenueStatus::options())
                            ->inline()
                            ->required()
                            ->default(VenueStatus::Draft->value)
                            ->columnSpanFull(),

                        Toggle::make('is_verified')
                            ->label(__('admin.fields.is_verified'))
                            ->helperText(__('admin.hints.is_verified')),

                        Toggle::make('auto_publish')
                            ->label(__('admin.fields.auto_publish'))
                            ->helperText(__('admin.hints.auto_publish')),

                        Toggle::make('is_nonprofit')
                            ->label(__('admin.fields.is_nonprofit')),

                        Select::make('plan')
                            ->label(__('admin.fields.plan'))
                            ->options(VenuePlan::options())
                            ->required()
                            ->default(VenuePlan::Free->value),

                        Textarea::make('rejection_reason')
                            ->label(__('admin.fields.rejection_reason'))
                            ->rows(2)
                            ->columnSpan(2),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.fields.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('municipality')
                    ->label(__('admin.fields.municipality'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (VenueType $state): string => $state->label()),

                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (VenueStatus $state): string => $state->label())
                    ->color(fn (VenueStatus $state): string => VenueModeration::statusColor($state)),

                TextColumn::make('events_count')
                    ->label(__('admin.fields.events_count'))
                    ->counts('events')
                    ->sortable(),

                IconColumn::make('is_verified')
                    ->label(__('admin.fields.is_verified'))
                    ->boolean(),

                IconColumn::make('auto_publish')
                    ->label(__('admin.fields.auto_publish'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('city')
                    ->label(__('admin.fields.city'))
                    ->relationship('city', 'name'),

                SelectFilter::make('type')
                    ->label(__('admin.fields.type'))
                    ->options(VenueType::options()),

                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(VenueStatus::options()),

                TernaryFilter::make('is_verified')
                    ->label(__('admin.fields.is_verified')),

                TernaryFilter::make('auto_publish')
                    ->label(__('admin.fields.auto_publish')),

                Filter::make('inactive')
                    ->label(__('admin.dashboard.inactive_venues'))
                    ->query(fn (Builder $query) => EditorialDashboardQuery::inactiveVenueScope($query)),
            ])
            ->recordActions([
                EditAction::make(),
                ...VenueModeration::actions(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            EventsRelationManager::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListVenues::route('/'),
            'create' => CreateVenue::route('/create'),
            'edit' => EditVenue::route('/{record}/edit'),
        ];
    }

    /**
     * Le chiavi sono quelle fissate da D19 (`mon`, `tue`, …) perché è il
     * formato salvato; i nomi visibili li dà il locale italiano di Carbon,
     * come per ogni altra data del progetto.
     *
     * @return array<string, string>
     */
    private static function weekdays(): array
    {
        $monday = CarbonImmutable::now()->startOfWeek();
        $days = [];

        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $index => $key) {
            $days[$key] = ucfirst($monday->addDays($index)->locale(app()->getLocale())->isoFormat('dddd'));
        }

        return $days;
    }

    private static function coordinatesPreview(Get $get): HtmlString
    {
        $lat = $get('lat');
        $lng = $get('lng');

        if (blank($lat) || blank($lng)) {
            return new HtmlString(e(__('admin.hints.coordinates')));
        }

        $label = __('admin.hints.lat_lng', ['lat' => $lat, 'lng' => $lng]);
        $url = sprintf('https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=17/%s/%s', $lat, $lng, $lat, $lng);

        return new HtmlString(
            e($label).' — <a class="fi-link" target="_blank" rel="noopener noreferrer" href="'.e($url).'">'
            .e(__('admin.actions.open_in_osm')).'</a>'
        );
    }
}
