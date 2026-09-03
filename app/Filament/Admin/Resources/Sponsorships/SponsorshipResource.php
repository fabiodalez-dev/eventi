<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Sponsorships;

use App\Enums\EventStatus;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Filament\Admin\Resources\Sponsorships\Pages\CreateSponsorship;
use App\Filament\Admin\Resources\Sponsorships\Pages\EditSponsorship;
use App\Filament\Admin\Resources\Sponsorships\Pages\ListSponsorships;
use App\Models\City;
use App\Models\Event;
use App\Models\Sponsorship;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le campagne sponsorizzate.
 *
 * **Sta in un gruppo suo e non fra i contenuti.** Una sponsorizzazione non è
 * una proprietà editoriale dell'evento: è un contratto, con un committente e un
 * importo, e chi la gestisce non è chi cura il catalogo. Tenerla accanto a
 * «Eventi» inviterebbe a trattarla come una spunta da mettere.
 */
class SponsorshipResource extends Resource
{
    protected static ?string $model = Sponsorship::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('sponsorships.admin.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('sponsorships.admin.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sponsorships.admin.title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            /*
             * **Schede, non due colonne.** Le cinque sezioni stavano
             * affiancate, e la griglia allinea le righe all'elemento piu'
             * alto: sotto «Cosa e dove» restava una colonna di vuoto lunga
             * quanto tutta «Quando». Tre schede, e ciascuna a piena
             * larghezza.
             */
            Tabs::make('sponsorship')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make(__('sponsorships.admin.tabs.campaign'))
                        ->schema([
                            Section::make(__('sponsorships.admin.sections.what'))
                                ->columns(2)
                                ->schema([
                                    Select::make('event_id')
                                        ->label(__('sponsorships.admin.fields.event'))
                                        ->required()
                                        ->searchable()
                                        ->preload()
                                        /*
                                 * **La tendina deve mostrare qualcosa appena si apre.**
                                 *
                                 * C'erano solo `getSearchResultsUsing` e
                                 * `getOptionLabelUsing`: `preload()` non ha niente da
                                 * precaricare senza `options()`, quindi l'elenco era
                                 * vuoto finche' non si digitava. Chi lo apriva leggeva
                                 * «Seleziona un'opzione» sopra il nulla e concludeva
                                 * che non ci fossero eventi da sponsorizzare — non che
                                 * dovesse scrivere per farli comparire.
                                 *
                                 * Si mostrano i pubblicati con una data futura, in
                                 * ordine di data: sono gli unici su cui una campagna
                                 * abbia senso oggi. Gli altri restano raggiungibili
                                 * scrivendo, con l'avviso qui sotto a dire perche' non
                                 * comparirebbero.
                                 */
                                        ->options(fn (): array => Event::query()
                                            ->where('status', EventStatus::Published)
                                            ->whereHas('occurrences', fn (Builder $futura): Builder => $futura->where('starts_at', '>=', now()))
                                            ->orderBy('id')
                                            ->limit(50)
                                            ->pluck('title', 'id')
                                            ->all())
                                        ->getSearchResultsUsing(fn (string $search): array => Event::query()
                                            ->where('title', 'like', '%'.$search.'%')
                                            ->orderByDesc('id')
                                            ->limit(30)
                                            ->pluck('title', 'id')
                                            ->all())
                                        ->getOptionLabelUsing(fn ($value): ?string => Event::query()->whereKey($value)->value('title'))
                                        /*
                                 * Una campagna su un evento non pubblicato non compare
                                 * mai (lo impedisce `Sponsorship::scopeVisible`), e chi
                                 * la sta creando deve saperlo QUI — non scoprirlo fra
                                 * tre giorni guardando le visualizzazioni ferme a zero.
                                 */
                                        ->helperText(function (Get $get): ?string {
                                            $event = Event::query()->whereKey($get('event_id'))->first();

                                            return $event !== null && $event->status !== EventStatus::Published
                                                ? __('sponsorships.admin.help.event_not_published')
                                                : null;
                                        })
                                        ->live()
                                        /* La città non si sceglie: è quella dell'evento. Un
                                   menu in più sarebbe un modo in più di sbagliare. */
                                        ->afterStateUpdated(function ($state, callable $set): void {
                                            $set('city_id', Event::query()->whereKey($state)->value('city_id'));
                                        })
                                        ->columnSpanFull(),

                                    Select::make('placement')
                                        ->label(__('sponsorships.admin.fields.placement'))
                                        ->options(SponsorshipPlacement::options())
                                        ->required()
                                        ->native(false)
                                        ->helperText(__('sponsorships.admin.help.placement')),

                                    Select::make('status')
                                        ->label(__('sponsorships.admin.fields.status'))
                                        ->options(SponsorshipStatus::options())
                                        ->default(SponsorshipStatus::Draft->value)
                                        ->required()
                                        ->native(false),
                                ]),
                        ]),

                    Tab::make(__('sponsorships.admin.tabs.period'))
                        ->schema([
                            Section::make(__('sponsorships.admin.sections.when'))
                                ->columns(3)
                                ->description(__('sponsorships.admin.help.window'))
                                ->schema([
                                    /*
                             * Gli istanti si salvano in UTC ma si scrivono nel fuso
                             * della città: senza `->timezone()` un «21:30» digitato qui
                             * finisce nel database come le 21:30 UTC, cioè le 23:30
                             * d'estate a Padova. È già successo sulle occorrenze.
                             */
                                    DateTimePicker::make('starts_at')
                                        ->label(__('sponsorships.admin.fields.starts_at'))
                                        ->seconds(false)
                                        ->required()
                                        ->timezone(fn (Get $get): string => self::cityTimezone($get('city_id'))),

                                    DateTimePicker::make('ends_at')
                                        ->label(__('sponsorships.admin.fields.ends_at'))
                                        ->seconds(false)
                                        ->required()
                                        ->after('starts_at')
                                        ->validationMessages(['after' => __('sponsorships.admin.validation.ends_after_starts')])
                                        ->timezone(fn (Get $get): string => self::cityTimezone($get('city_id'))),

                                    TextInput::make('priority')
                                        ->label(__('sponsorships.admin.fields.priority'))
                                        ->numeric()
                                        ->default(0)
                                        ->minValue(0)
                                        ->maxValue(1000)
                                        ->helperText(__('sponsorships.admin.help.priority')),

                                    /*
                             * Priorità e peso vendono due cose diverse, e la
                             * differenza va spiegata qui perché è dove si sbaglia: la
                             * priorità è una posizione — «sei sempre in cima» — e il
                             * peso è una quota — «compari tre volte su quattro».
                             *
                             * Con la sola priorità, in una collocazione da uno solo,
                             * chi ha comprato meno non compare MAI: c'è un posto e lo
                             * prende sempre lo stesso. Il peso è ciò che permette di
                             * vendere lo stesso spazio a più clienti.
                             */
                                    TextInput::make('weight')
                                        ->label(__('sponsorships.admin.fields.weight'))
                                        ->numeric()
                                        ->default(1)
                                        ->minValue(1)
                                        ->maxValue(100)
                                        ->helperText(__('sponsorships.admin.help.weight')),
                                ]),

                            Section::make(__('sponsorships.admin.sections.caps'))
                                ->description(__('sponsorships.admin.sections.caps_lead'))
                                ->columns(2)
                                ->schema([
                                    /*
                             * Vuoti significa «nessun tetto», che è come si è sempre
                             * venduto qui: a tempo. Il tetto serve a chi compra un
                             * numero di visualizzazioni invece di un periodo, ed è il
                             * modo in cui si vende pubblicità quasi ovunque.
                             *
                             * Raggiunto il tetto la campagna smette di comparire ma
                             * NON cambia stato: è finita per esaurimento, non sospesa
                             * da qualcuno, e la differenza si deve poter leggere.
                             */
                                    TextInput::make('impressions_cap')
                                        ->label(__('sponsorships.admin.fields.impressions_cap'))
                                        ->numeric()
                                        ->minValue(1)
                                        ->helperText(__('sponsorships.admin.help.impressions_cap')),

                                    TextInput::make('clicks_cap')
                                        ->label(__('sponsorships.admin.fields.clicks_cap'))
                                        ->numeric()
                                        ->minValue(1)
                                        ->helperText(__('sponsorships.admin.help.clicks_cap')),
                                ]),
                        ]),

                    Tab::make(__('sponsorships.admin.tabs.client'))
                        ->schema([
                            Section::make(__('sponsorships.admin.sections.who'))
                                ->columns(3)
                                ->schema([
                                    TextInput::make('advertiser_name')
                                        ->label(__('sponsorships.admin.fields.advertiser_name'))
                                        ->required()
                                        ->maxLength(255)
                                        ->helperText(__('sponsorships.admin.help.advertiser_name')),

                                    TextInput::make('advertiser_email')
                                        ->label(__('sponsorships.admin.fields.advertiser_email'))
                                        ->email()
                                        ->maxLength(255),

                                    TextInput::make('advertiser_url')
                                        ->label(__('sponsorships.admin.fields.advertiser_url'))
                                        ->url()
                                        ->maxLength(255),
                                ]),

                            Section::make(__('sponsorships.admin.sections.money'))
                                ->columns(3)
                                ->schema([
                                    TextInput::make('amount_cents')
                                        ->label(__('sponsorships.admin.fields.amount'))
                                        ->numeric()
                                        ->minValue(0)
                                        /* In centesimi nel database, in euro nel modulo: i
                                   decimali in virgola mobile sommati mille volte non
                                   tornano, e chi compila un modulo non scrive
                                   centesimi. */
                                        ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : number_format($state / 100, 2, '.', ''))
                                        ->dehydrateStateUsing(fn (?string $state): ?int => $state === null || $state === '' ? null : (int) round((float) $state * 100))
                                        ->prefix('€'),

                                    TextInput::make('currency')
                                        ->label(__('sponsorships.admin.fields.currency'))
                                        ->default('EUR')
                                        ->maxLength(3),

                                    TextInput::make('invoice_reference')
                                        ->label(__('sponsorships.admin.fields.invoice_reference'))
                                        ->maxLength(255),

                                    Textarea::make('notes')
                                        ->label(__('sponsorships.admin.fields.notes'))
                                        ->rows(3)
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
                TextColumn::make('event.title')
                    ->label(__('sponsorships.admin.fields.event'))
                    ->searchable()
                    ->wrap()
                    ->limit(60),

                TextColumn::make('placement')
                    ->label(__('sponsorships.admin.fields.placement'))
                    ->badge()
                    ->formatStateUsing(fn (SponsorshipPlacement $state): string => $state->label()),

                TextColumn::make('status')
                    ->label(__('sponsorships.admin.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (SponsorshipStatus $state): string => $state->label())
                    ->color(fn (SponsorshipStatus $state): string => match ($state) {
                        SponsorshipStatus::Active => 'success',
                        SponsorshipStatus::Paused => 'warning',
                        SponsorshipStatus::Draft => 'gray',
                    }),

                /*
                 * La fase è calcolata, non salvata: uno stato che deve essere
                 * aggiornato da un processo notturno per restare vero è uno
                 * stato che prima o poi mente.
                 *
                 * Era un sì/no. Diceva il meno utile delle due cose: che una
                 * campagna non sta girando si vede anche dallo stato, mentre
                 * il **perché** — non è ancora cominciata, è finita, l'hanno
                 * sospesa — è quello che si sta cercando di capire aprendo
                 * l'elenco, e costringeva a leggere le due date e fare il
                 * conto a mente.
                 */
                TextColumn::make('phase')
                    ->label(__('sponsorships.admin.fields.phase'))
                    ->badge()
                    ->state(fn (Sponsorship $record): string => $record->phase()->label())
                    ->color(fn (Sponsorship $record): string => $record->phase()->color()),

                TextColumn::make('starts_at')
                    ->label(__('sponsorships.admin.fields.starts_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('ends_at')
                    ->label(__('sponsorships.admin.fields.ends_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('advertiser_name')
                    ->label(__('sponsorships.admin.fields.advertiser_name'))
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('impressions')
                    ->label(__('sponsorships.admin.fields.impressions'))
                    ->numeric()
                    ->sortable(),

                TextColumn::make('clicks')
                    ->label(__('sponsorships.admin.fields.clicks'))
                    ->numeric()
                    ->sortable(),

                TextColumn::make('click_rate')
                    ->label(__('sponsorships.admin.fields.click_rate'))
                    /* Trattino e non «0%» quando non è mai stata mostrata: un
                       rapporto su zero non è zero, è una domanda senza
                       risposta, e scriverlo come zero fa concludere che la
                       campagna vada male quando non è ancora partita. */
                    ->state(fn (Sponsorship $record): string => $record->clickRate() === null
                        ? '—'
                        : number_format($record->clickRate() * 100, 1).'%'),
            ])
            ->defaultSort('ends_at', 'desc')
            ->filters([
                SelectFilter::make('placement')
                    ->label(__('sponsorships.admin.filters.placement'))
                    ->options(SponsorshipPlacement::options()),

                SelectFilter::make('status')
                    ->label(__('sponsorships.admin.filters.status'))
                    ->options(SponsorshipStatus::options()),

                Filter::make('running')
                    ->label(__('sponsorships.admin.filters.running'))
                    /* `Sponsorship::query()->visible()` e non `$query->visible()`:
                       il filtro riceve un builder generico, su cui lo scope del
                       modello non e' visibile ne' a chi legge ne' all'analisi
                       statica. Si riusa la stessa condizione applicandola
                       sull'oggetto giusto. */
                    ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereKey(
                        Sponsorship::query()->visible()->pluck('id')
                    )),
            ])
            ->recordActions([
                EditAction::make(),
                self::toggleAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('sponsorships.admin.empty.title'))
            ->emptyStateDescription(__('sponsorships.admin.empty.body'));
    }

    /**
     * Attiva o sospende con un tocco: è l'operazione più frequente — un
     * pagamento che non arriva, una campagna da far partire — e passare dal
     * modulo di modifica per cambiare un menu a tendina è tre gesti invece di
     * uno.
     */
    private static function toggleAction(): Action
    {
        return Action::make('toggle')
            ->label(fn (Sponsorship $record): string => $record->status === SponsorshipStatus::Active
                ? __('sponsorships.admin.actions.pause')
                : __('sponsorships.admin.actions.activate'))
            ->icon(fn (Sponsorship $record): Heroicon => $record->status === SponsorshipStatus::Active
                ? Heroicon::OutlinedPause
                : Heroicon::OutlinedPlay)
            ->requiresConfirmation()
            ->action(function (Sponsorship $record): void {
                $attiva = $record->status !== SponsorshipStatus::Active;

                $record->update(['status' => $attiva ? SponsorshipStatus::Active : SponsorshipStatus::Paused]);

                Notification::make()
                    ->title($attiva ? __('sponsorships.admin.actions.activated') : __('sponsorships.admin.actions.paused'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Il fuso della città dell'evento, per i due selettori di data.
     */
    private static function cityTimezone(mixed $cityId): string
    {
        $timezone = $cityId === null
            ? null
            : City::query()->whereKey($cityId)->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : config()->string('app.timezone');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSponsorships::route('/'),
            'create' => CreateSponsorship::route('/create'),
            'edit' => EditSponsorship::route('/{record}/edit'),
        ];
    }
}
