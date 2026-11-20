<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Sponsorships;

use App\Enums\EventStatus;
use App\Enums\SponsorshipPacing;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Filament\Admin\Resources\Sponsorships\Pages\CreateSponsorship;
use App\Filament\Admin\Resources\Sponsorships\Pages\EditSponsorship;
use App\Filament\Admin\Resources\Sponsorships\Pages\ListSponsorships;
use App\Filament\Admin\Resources\Sponsorships\Support\SponsorshipProgress;
use App\Filament\Admin\Resources\Sponsorships\Support\SponsorshipWizard;
use App\Filament\Concerns\HasSentenceCaseNavigationLabel;
use App\Filament\Forms\Components\EventPicker;
use App\Filament\Support\BulkActions;
use App\Filament\Support\PosterColumn;
use App\Models\City;
use App\Models\Event;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Services\Sponsorship\Delivery\PacingState;
use BackedEnum;
use Filament\Actions\Action;
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
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
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
    use HasSentenceCaseNavigationLabel;

    protected static ?string $model = Sponsorship::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.promotion');
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
        return $schema->columns(1)->components([
            Section::make(__('sponsorships.progress.heading'))
                ->description(__('sponsorships.progress.lead'))
                ->visibleOn('edit')
                ->collapsible()
                ->schema([
                    View::make('filament.admin.sponsorships.progress-detail')
                        ->viewData(fn (?Sponsorship $record): array => self::progressData($record)),
                ]),

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
                                    /*
                                     * Il selettore dei pannelli con locandina, data e
                                     * locale, solo fra gli eventi sponsorizzabili
                                     * (`EventPicker`). La campagna che si modifica tiene
                                     * il suo evento anche quando non lo è più: senza,
                                     * una campagna finita non si potrebbe più salvare.
                                     */
                                    EventPicker::make(
                                        'event_id',
                                        fn (): Builder => Event::query(),
                                        fn (?Sponsorship $record): ?int => $record?->event_id === null ? null : (int) $record->event_id,
                                    )
                                        ->required()
                                        ->validationMessages(['in' => __('sponsorships.wizard.validation.event_not_sponsorable')])
                                        /*
                                         * Una campagna su un evento non pubblicato non compare
                                         * mai (lo impedisce `Sponsorship::scopeVisible`), e chi
                                         * la modifica deve saperlo QUI.
                                         */
                                        ->helperText(function (Get $get): ?string {
                                            $event = Event::query()->whereKey($get('event_id'))->first();

                                            return $event !== null && $event->status !== EventStatus::Published
                                                ? __('sponsorships.admin.help.event_not_published')
                                                : null;
                                        })
                                        ->live()
                                        ->disabled(fn (?Sponsorship $record): bool => $record?->sponsorship_grant_id !== null)
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
                                ->columns(['default' => 1, 'lg' => 2])
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
                                        ->helperText(fn (?Sponsorship $record): string => $record?->sponsorship_grant_id !== null
                                            ? __('sponsorships.admin.help.delivery_on_grant')
                                            : __('sponsorships.admin.help.priority'))
                                        ->disabled(fn (?Sponsorship $record): bool => $record?->sponsorship_grant_id !== null),

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
                                        ->helperText(__('sponsorships.admin.help.weight'))
                                        ->disabled(fn (?Sponsorship $record): bool => $record?->sponsorship_grant_id !== null),
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

                    Tab::make(__('sponsorships.admin.tabs.delivery'))
                        ->schema([
                            Section::make(__('sponsorships.admin.sections.delivery'))
                                ->description(fn (?Sponsorship $record): string => $record?->sponsorship_grant_id !== null
                                    ? __('sponsorships.admin.help.delivery_on_grant')
                                    : __('sponsorships.admin.sections.delivery_lead'))
                                ->columns(['default' => 1, 'lg' => 2])
                                ->schema([
                                    TextInput::make('impressions_goal')
                                        ->label(__('sponsorships.admin.fields.impressions_goal'))
                                        ->helperText(fn (Get $get): string => SponsorshipWizard::periodOnlyMessage($get('placement'))
                                            ?? __('sponsorships.admin.help.impressions_goal'))
                                        ->integer()
                                        ->minValue(1)
                                        ->maxValue(10_000_000)
                                        ->prohibitedIf('placement', SponsorshipPlacement::periodOnlyValues())
                                        ->validationMessages(['prohibited_if' => __('sponsorships.wizard.help.period_only')])
                                        ->disabled(fn (?Sponsorship $record): bool => $record?->sponsorship_grant_id !== null),

                                    Select::make('pacing')
                                        ->label(__('sponsorships.wizard.fields.pacing'))
                                        ->options(SponsorshipWizard::pacingOptions())
                                        ->default(SponsorshipPacing::Even->value)
                                        ->native(false)
                                        ->required()
                                        ->disabled(fn (?Sponsorship $record): bool => $record?->sponsorship_grant_id !== null),
                                ]),
                        ]),

                    Tab::make(__('sponsorships.admin.tabs.client'))
                        ->schema([
                            Section::make(__('sponsorships.admin.sections.who'))
                                ->columns(['default' => 1, 'lg' => 2])
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
                                ->columns(['default' => 1, 'lg' => 2])
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
        /* Una sola istanza per l'elenco: previsioni e unità già calcolate
           restano in memoria fra una riga e l'altra. */
        $progress = app(SponsorshipProgress::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['event.media', 'event.city', 'city', 'grant.venue', 'grant.organizer']))
            ->columns([
                PosterColumn::make('event.poster_media'),

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

                /* L'avanzamento dell'unità: barra, consegnate su obiettivo e
                   stato. Lo stato (testo) è anche ciò che va nell'esportazione. */
                ViewColumn::make('progress')
                    ->label(__('sponsorships.progress.column'))
                    ->view('filament.admin.sponsorships.progress-column')
                    ->state(fn (Sponsorship $record): string => $progress->forSponsorship($record)?->stateLabel() ?? '—')
                    ->viewData(fn (Sponsorship $record): array => ['progress' => $progress->forSponsorship($record)]),

                /* Nel fuso della citta' della campagna, come nel modulo: in UTC
                   una fine alle 23:59 si leggeva alle 21:59. */
                TextColumn::make('starts_at')
                    ->label(__('sponsorships.admin.fields.starts_at'))
                    ->dateTime('d/m/Y H:i')
                    ->timezone(fn (Sponsorship $record): string => $record->city?->timezone ?: config()->string('app.timezone'))
                    ->sortable(),

                TextColumn::make('ends_at')
                    ->label(__('sponsorships.admin.fields.ends_at'))
                    ->dateTime('d/m/Y H:i')
                    ->timezone(fn (Sponsorship $record): string => $record->city?->timezone ?: config()->string('app.timezone'))
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

                SelectFilter::make('pacing_state')
                    ->label(__('sponsorships.progress.filter'))
                    ->options(self::pacingStateOptions())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereKey($progress->sponsorshipIdsInState((string) $data['value']))),

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
                BulkActions::make(),
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

    /** @return array<string, string> */
    public static function pacingStateOptions(): array
    {
        $options = [];

        foreach ([...array_map(fn (PacingState $state): string => $state->value, PacingState::cases()), 'period'] as $key) {
            $options[$key] = __('sponsorships.pacing_state.'.$key);
        }

        return $options;
    }

    /**
     * I dati della scheda «Avanzamento»: l'unità a cui appartiene la riga, la
     * consegna giorno per giorno e, per le abilitazioni, i loro eventi.
     *
     * @return array<string, mixed>
     */
    public static function progressData(Sponsorship|SponsorshipGrant|null $record): array
    {
        $service = app(SponsorshipProgress::class);

        if ($record === null) {
            return ['progress' => null];
        }

        $grant = $record instanceof SponsorshipGrant ? $record : $record->grant;

        if ($grant instanceof SponsorshipGrant) {
            $unit = $service->forGrant($grant);
            $city = City::query()->find($grant->sponsorCityId());
            $placement = $grant->placement;
            $ids = Sponsorship::withTrashed()->where('sponsorship_grant_id', $grant->getKey())->pluck('id')->map(fn ($id): int => (int) $id)->all();
        } else {
            /* Senza abilitazione l'unità è la riga stessa. */
            $unit = $service->forSponsorship($record);
            $city = $record->city;
            $placement = $record->placement;
            $ids = [(int) $record->getKey()];
        }

        if ($unit === null) {
            return ['progress' => null];
        }

        $forecast = $city instanceof City ? $service->forecast($city, $placement) : null;

        return [
            'progress' => $unit,
            'daily' => $forecast === null ? [] : $service->daily($unit, $ids, $forecast),
            'events' => $grant instanceof SponsorshipGrant ? $service->grantEvents($grant) : [],
            'quotaMax' => $service->quotaMax(),
            'minDays' => (int) config('sponsorship.forecast_min_days', 14),
            'provisional' => $forecast?->isProvisional() ?? true,
        ];
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
