<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ImportSources;

use App\Enums\ImportSourceType;
use App\Filament\Admin\Resources\ImportSources\Pages\CreateImportSource;
use App\Filament\Admin\Resources\ImportSources\Pages\EditImportSource;
use App\Filament\Admin\Resources\ImportSources\Pages\ListImportSources;
use App\Models\City;
use App\Models\ImportSource;
use App\Queries\EditorialDashboardQuery;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * §14.2 — le sorgenti di import. Qui si dichiarano e si sorvegliano: l'ultima
 * esecuzione, il suo esito e l'errore che l'ha fermata.
 *
 * `credentials` è cifrata dal cast del model e **non viene mai ripresentata**
 * nel modulo: un campo che si riempie da solo con il segreto lo mostrerebbe a
 * chiunque apra la scheda, e basterebbe un salvataggio distratto per
 * ricifrarlo su se stesso.
 */
class ImportSourceResource extends Resource
{
    protected static ?string $model = ImportSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.system');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.import_source.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.import_source.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.sections.connection'))
                    ->columns(2)
                    ->schema([
                        Select::make('city_id')
                            ->label(__('admin.fields.city'))
                            ->relationship('city', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => City::query()->orderBy('id')->value('id')),

                        Select::make('venue_id')
                            ->label(__('admin.fields.venue'))
                            ->relationship('venue', 'name')
                            ->searchable()
                            ->preload(),

                        Select::make('type')
                            ->label(__('admin.fields.type'))
                            ->options(ImportSourceType::options())
                            ->required()
                            ->default(ImportSourceType::Ics->value),

                        Select::make('default_category_id')
                            ->label(__('admin.fields.default_category'))
                            ->relationship('defaultCategory', 'name')
                            ->searchable()
                            ->preload(),

                        TextInput::make('url')
                            ->label(__('admin.fields.url'))
                            ->url()
                            ->maxLength(1000)
                            ->columnSpanFull(),

                        TextInput::make('credentials')
                            ->label(__('admin.fields.credentials'))
                            ->helperText(__('admin.hints.credentials'))
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->afterStateHydrated(fn (TextInput $component): mixed => $component->state(null))
                            ->columnSpanFull(),

                        KeyValue::make('mapping')
                            ->label(__('admin.fields.mapping'))
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label(__('admin.fields.is_active'))
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('city.name')
                    ->label(__('admin.fields.city')),

                TextColumn::make('venue.name')
                    ->label(__('admin.fields.venue'))
                    ->placeholder(__('admin.placeholders.none')),

                TextColumn::make('type')
                    ->label(__('admin.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (ImportSourceType $state): string => $state->label()),

                TextColumn::make('url')
                    ->label(__('admin.fields.url'))
                    ->limit(50)
                    ->placeholder(__('admin.placeholders.none')),

                TextColumn::make('last_run_at')
                    ->label(__('admin.fields.last_run_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('admin.placeholders.never'))
                    ->sortable(),

                TextColumn::make('last_error')
                    ->label(__('admin.fields.last_error'))
                    ->limit(60)
                    ->color('danger')
                    ->placeholder(__('admin.placeholders.none')),

                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean(),
            ])
            ->defaultSort('last_run_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.fields.type'))
                    ->options(ImportSourceType::options()),

                TernaryFilter::make('is_active')
                    ->label(__('admin.fields.is_active')),

                Filter::make('failed')
                    ->label(__('admin.dashboard.import_failures'))
                    ->query(fn (Builder $query) => EditorialDashboardQuery::failedImportScope($query)),
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
            'index' => ListImportSources::route('/'),
            'create' => CreateImportSource::route('/create'),
            'edit' => EditImportSource::route('/{record}/edit'),
        ];
    }
}
