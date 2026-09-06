<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cities;

use App\Filament\Admin\Resources\Cities\Pages\CreateCity;
use App\Filament\Admin\Resources\Cities\Pages\EditCity;
use App\Filament\Admin\Resources\Cities\Pages\ListCities;
use App\Filament\Support\EditorialFields;
use App\Models\City;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * §9.2 — la città. È il record da cui dipende tutto il resto: senza, non
 * esistono locali né eventi.
 *
 * Tre campi non sono anagrafici ma **governano il motore temporale** e per
 * questo portano un testo di aiuto esplicito: il fuso (§8.1, "adesso" è
 * sempre l'ora locale della città), l'ora di chiusura della giornata notturna
 * (§8.2, `business_date`) e i minuti di "inizia tra poco" (§8.4).
 */
class CityResource extends Resource
{
    protected static ?string $model = City::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.places');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.city.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.city.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)
            ->components([
                EditorialFields::content(false, false),
                EditorialFields::seo(),
                Section::make(__('admin.sections.identity'))
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

                        TextInput::make('province_code')
                            ->label(__('admin.fields.province_code'))
                            ->required()
                            ->maxLength(4),

                        TextInput::make('province_name')
                            ->label(__('admin.fields.province_name'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('region')
                            ->label(__('admin.fields.region'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('country_code')
                            ->label(__('admin.fields.country_code'))
                            ->required()
                            ->default('IT')
                            ->maxLength(2),
                    ]),

                Section::make(__('admin.sections.geography'))
                    ->description(__('admin.hints.coordinates'))
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema([
                        TextInput::make('center_lat')
                            ->label(__('admin.fields.center_lat'))
                            ->required()
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90),

                        TextInput::make('center_lng')
                            ->label(__('admin.fields.center_lng'))
                            ->required()
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180),

                        TextInput::make('default_zoom')
                            ->label(__('admin.fields.default_zoom'))
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(20)
                            ->default(12),

                        TextInput::make('radius_km')
                            ->label(__('admin.fields.radius_km'))
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(500)
                            ->default(30),

                        Fieldset::make(__('admin.fields.bounds'))
                            ->columns(['default' => 1, 'lg' => 2])
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('bounds.north')->label(__('admin.compass.north'))->numeric(),
                                TextInput::make('bounds.south')->label(__('admin.compass.south'))->numeric(),
                                TextInput::make('bounds.east')->label(__('admin.compass.east'))->numeric(),
                                TextInput::make('bounds.west')->label(__('admin.compass.west'))->numeric(),
                            ]),
                    ]),

                Section::make(__('admin.sections.temporal'))
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema([
                        Select::make('timezone')
                            ->label(__('admin.fields.timezone'))
                            ->required()
                            ->searchable()
                            ->options(array_combine(
                                timezone_identifiers_list(),
                                timezone_identifiers_list(),
                            ))
                            ->default('Europe/Rome'),

                        TimePicker::make('night_cutoff_time')
                            ->label(__('admin.fields.night_cutoff_time'))
                            ->helperText(__('admin.hints.night_cutoff_time'))
                            ->seconds(false)
                            ->required()
                            ->default('06:00'),

                        TextInput::make('starting_soon_minutes')
                            ->label(__('admin.fields.starting_soon_minutes'))
                            ->helperText(__('admin.hints.starting_soon_minutes'))
                            ->required()
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(1440)
                            ->default(180),

                        Select::make('locale')
                            ->label(__('admin.fields.locale'))
                            ->required()
                            ->options(['it' => 'it', 'en' => 'en', 'de' => 'de'])
                            ->default('it'),
                    ]),

                Section::make(__('admin.sections.features'))
                    ->description(__('admin.hints.settings'))
                    ->schema([
                        Toggle::make('is_active')
                            ->label(__('admin.fields.is_active')),

                        Repeater::make('settings')
                            ->label(__('admin.fields.settings'))
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel(__('admin.actions.add_flag'))
                            ->schema([
                                TextInput::make('key')
                                    ->label(__('admin.fields.setting_key'))
                                    ->required()
                                    ->maxLength(64),

                                Toggle::make('enabled')
                                    ->label(__('admin.fields.is_active'))
                                    ->inline(false),
                            ]),
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

                TextColumn::make('province_name')
                    ->label(__('admin.fields.province_name'))
                    ->searchable(),

                TextColumn::make('timezone')
                    ->label(__('admin.fields.timezone'))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('night_cutoff_time')
                    ->label(__('admin.fields.night_cutoff_time'))
                    ->time('H:i'),

                TextColumn::make('starting_soon_minutes')
                    ->label(__('admin.fields.starting_soon_minutes'))
                    ->numeric(),

                TextColumn::make('venues_count')
                    ->label(__('admin.fields.venues_count'))
                    ->counts('venues'),

                TextColumn::make('events_count')
                    ->label(__('admin.fields.events_count'))
                    ->counts('events'),

                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin.fields.is_active')),
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

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCities::route('/'),
            'create' => CreateCity::route('/create'),
            'edit' => EditCity::route('/{record}/edit'),
        ];
    }
}
