<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ImportSources;

use App\Enums\ImportRunStatus;
use App\Enums\ImportSourceType;
use App\Exceptions\ImportException;
use App\Filament\Admin\Resources\ImportSources\Pages\CreateImportSource;
use App\Filament\Admin\Resources\ImportSources\Pages\EditImportSource;
use App\Filament\Admin\Resources\ImportSources\Pages\ListImportSources;
use App\Filament\Admin\Resources\ImportSources\RelationManagers\RunsRelationManager;
use App\Filament\Support\ImportPreviewRows;
use App\Jobs\Import\ImportSourceJob;
use App\Models\City;
use App\Models\ImportSource;
use App\Queries\EditorialDashboardQuery;
use App\Rules\SafeImportUrl;
use App\Services\Import\ImportDriverFactory;
use App\Services\Import\ImportRunner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
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
        return $schema->columns(1)
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

                        /*
                         * Il locale è **facoltativo**: §14.2 prevede anche le
                         * sorgenti che nessun locale dichiara — il calendario
                         * del Comune, un teatro non ancora iscritto. Quelle
                         * nascono eventi senza `venue_id`, e per non restare
                         * senza classificazione hanno bisogno di una categoria
                         * predefinita: è la sola combinazione in cui il campo
                         * qui sotto diventa obbligatorio.
                         */
                        Select::make('venue_id')
                            ->label(__('admin.fields.venue'))
                            ->relationship('venue', 'name')
                            ->helperText(__('admin.import.no_venue_hint'))
                            ->searchable()
                            ->preload()
                            ->live(),

                        Select::make('type')
                            ->label(__('admin.fields.type'))
                            ->options(ImportSourceType::options())
                            ->required()
                            ->default(ImportSourceType::Ics->value),

                        Select::make('default_category_id')
                            ->label(__('admin.fields.default_category'))
                            ->relationship('defaultCategory', 'name')
                            ->searchable()
                            ->preload()
                            ->required(fn (Get $get): bool => blank($get('venue_id'))),

                        /*
                         * `SafeImportUrl` è la stessa difesa che il driver
                         * applica prima di scaricare: qui serve a dirlo mentre
                         * si scrive l'indirizzo, invece di lasciarlo scoprire
                         * alla prima esecuzione.
                         */
                        TextInput::make('url')
                            ->label(__('admin.fields.url'))
                            ->url()
                            ->maxLength(1000)
                            ->rule(new SafeImportUrl)
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

                /*
                 * `last_status` è una stringa libera per contratto (lo dice
                 * `EditorialDashboardQuery`): un valore che l'enum non conosce
                 * si mostra così com'è invece di far esplodere l'elenco.
                 */
                TextColumn::make('last_status')
                    ->label(__('admin.fields.last_status'))
                    ->badge()
                    ->placeholder(__('admin.placeholders.never'))
                    ->formatStateUsing(fn (string $state): string => ImportRunStatus::tryFrom($state)?->label() ?? $state)
                    ->color(fn (string $state): string => match (ImportRunStatus::tryFrom($state)) {
                        ImportRunStatus::Success => 'success',
                        ImportRunStatus::Partial => 'warning',
                        ImportRunStatus::Failed => 'danger',
                        default => 'gray',
                    }),

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
                self::previewAction(),
                self::runAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * L'anteprima obbligatoria di §14.2. Con la pubblicazione diretta (D32) è
     * il contrappeso che regge tutto il resto: si guarda **prima** di accendere
     * la sorgente, e mostra le date che entrerebbero davvero — filtro di
     * esclusione già applicato, fusi già risolti.
     *
     * Non scrive niente e non tocca `last_run_at`: guardare non è eseguire.
     */
    private static function previewAction(): Action
    {
        return Action::make('preview')
            ->label(__('admin.actions.preview_import'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->modalHeading(__('admin.import.preview_heading'))
            ->modalDescription(__('admin.import.preview_description'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('admin.actions.close'))
            ->visible(fn (ImportSource $record): bool => app(ImportDriverFactory::class)->supports($record))
            ->modalContent(function (ImportSource $record): View {
                $rows = [];
                $error = null;

                try {
                    $rows = ImportPreviewRows::make(
                        app(ImportRunner::class)->preview($record),
                        $record->city->timezone,
                    );
                } catch (ImportException $exception) {
                    $error = $exception->getMessage();
                }

                return view('filament.import.preview', ['rows' => $rows, 'error' => $error]);
            });
    }

    /**
     * L'esecuzione a mano, che accoda lo stesso lavoro dell'esecuzione oraria.
     * Non esegue nel processo della richiesta: un calendario lento terrebbe
     * aperta la pagina finché non risponde, e i tentativi con attesa crescente
     * appartengono alla coda.
     */
    private static function runAction(): Action
    {
        return Action::make('run')
            ->label(__('admin.actions.run_import'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('admin.import.run_confirm'))
            ->visible(fn (ImportSource $record): bool => app(ImportDriverFactory::class)->supports($record))
            ->action(function (ImportSource $record): void {
                ImportSourceJob::dispatch((int) $record->getKey());

                Notification::make()
                    ->title(__('admin.notifications.import_queued'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Lo storico di §14.2: le ultime esecuzioni della sorgente, che sono ciò
     * che risponde a «da quando non arriva più niente» — domanda a cui le
     * colonne `last_*` non sanno rispondere perché conservano solo l'ultima.
     *
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            RunsRelationManager::class,
        ];
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
