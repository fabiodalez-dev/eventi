<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Sponsorships\Support;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipPacing;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\VenueStatus;
use App\Filament\Forms\Components\EventPicker;
use App\Models\City;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\SponsorshipPrice;
use App\Models\Venue;
use App\Services\Sponsorship\GrantCampaigns;
use App\Services\Sponsorship\GrantPayment;
use App\Support\Sponsorship\SponsorableEvents;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Il percorso in quattro passi di `/admin/sponsorships/create`.
 *
 * 1. **Committente**: un locale, un organizzatore, oppure un committente
 *    esterno (l'etichetta che promuove un concerto in un circolo non suo). Il
 *    locale e l'organizzatore si cercano per nome e compilano da soli nome e
 *    contatti di chi paga, che restano modificabili.
 * 2. **Cosa**: un evento (dal selettore con locandina, data e locale, solo fra
 *    quelli sponsorizzabili del committente) oppure «tutti gli eventi».
 * 3. **Dove e quanto**: collocazione con anteprima, periodo nel fuso della
 *    città, prodotto a periodo o a visualizzazioni con la stima di quanto lo
 *    spazio può dare e chi lo occupa già.
 * 4. **Pagamento e riepilogo**: importo dal listino, pagamento o gratuità con
 *    motivo, priorità e peso fra le opzioni avanzate, e il riepilogo in chiaro.
 *
 * «Un evento» salva una campagna singola, come prima. «Tutti gli eventi» salva
 * un'abilitazione automatica e fa subito la prima sincronizzazione: le righe
 * degli eventi nascono insieme all'abilitazione e quelle future da sole.
 *
 * Ogni passo valida i suoi campi sul server quando si va avanti, con i
 * messaggi in italiano del progetto: niente più salto di scheda con il
 * messaggio del browser in inglese.
 */
final class SponsorshipWizard
{
    public const SPONSOR_VENUE = 'venue';

    public const SPONSOR_ORGANIZER = 'organizer';

    public const SPONSOR_EXTERNAL = 'external';

    public const SCOPE_EVENT = 'event';

    public const SCOPE_ALL = 'all';

    public const PRODUCT_PERIOD = 'period';

    public const PRODUCT_VIEWS = 'views';

    /** @var list<int> */
    public const MONTHS = [1, 2, 3, 6, 12];

    /** @return list<Step> */
    public static function steps(): array
    {
        return [
            self::sponsorStep(),
            self::whatStep(),
            self::whereStep(),
            self::paymentStep(),
        ];
    }

    /**
     * Salva ciò che il percorso ha raccolto: una campagna singola oppure
     * un'abilitazione automatica con le sue righe.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data, ?int $userId): Sponsorship|SponsorshipGrant
    {
        $type = (string) ($data['sponsor_type'] ?? self::SPONSOR_EXTERNAL);
        $scope = $type === self::SPONSOR_EXTERNAL ? self::SCOPE_EVENT : (string) ($data['scope'] ?? self::SCOPE_EVENT);
        $views = ($data['product'] ?? self::PRODUCT_PERIOD) === self::PRODUCT_VIEWS;
        $complimentary = (bool) ($data['complimentary'] ?? false);
        $active = ($data['status'] ?? SponsorshipStatus::Active->value) === SponsorshipStatus::Active->value;
        $placement = SponsorshipPlacement::from((string) $data['placement']);
        $sponsor = self::sponsor($type, $data['venue_id'] ?? null, $data['organizer_id'] ?? null);

        $delivery = [
            'priority' => self::intOr($data['priority'] ?? null, 0),
            'weight' => self::intOr($data['weight'] ?? null, 1),
            'impressions_goal' => $views ? self::intOr($data['impressions_goal'] ?? null, 0) : null,
            'pacing' => $views ? (string) ($data['pacing'] ?? SponsorshipPacing::Even->value) : SponsorshipPacing::Even->value,
        ];

        if ($scope === self::SCOPE_ALL) {
            if ($sponsor === null) {
                throw ValidationException::withMessages(['data.venue_id' => __('sponsorships.wizard.validation.sponsor_required')]);
            }

            Gate::authorize('create', SponsorshipGrant::class);

            return DB::transaction(function () use ($data, $sponsor, $placement, $complimentary, $active, $delivery, $userId): SponsorshipGrant {
                $grant = SponsorshipGrant::query()->create(app(GrantPayment::class)->normalize([
                    'venue_id' => $sponsor instanceof Venue ? $sponsor->getKey() : null,
                    'organizer_id' => $sponsor instanceof Organizer ? $sponsor->getKey() : null,
                    'created_by' => $userId,
                    'mode' => PromotionMode::Automatic,
                    'placement' => $placement,
                    'enabled' => $active,
                    'complimentary' => $complimentary,
                    'starts_at' => $data['starts_at'],
                    'ends_at' => $data['ends_at'],
                    'amount_cents' => $complimentary ? null : ($data['amount_cents'] ?? null),
                    'paid_at' => $complimentary ? null : ($data['paid_at'] ?? null),
                    'payment_method' => $complimentary ? null : ($data['payment_method'] ?? null),
                    'payment_reference' => $complimentary ? null : ($data['payment_reference'] ?? null),
                    'notes' => $data['notes'] ?? null,
                    ...$delivery,
                ]));

                app(GrantCampaigns::class)->sync($grant);

                return $grant;
            });
        }

        $event = Event::query()->find($data['event_id'] ?? null);

        /* Il selettore accetta già solo eventi sponsorizzabili del committente;
           lo ricontrollo qui perché il salvataggio non deve fidarsi della pagina. */
        if (! $event instanceof Event || ! SponsorableEvents::isSponsorable($event) || ($sponsor !== null && ! SponsorableEvents::belongsTo($event, $sponsor))) {
            throw ValidationException::withMessages(['data.event_id' => __('sponsorships.wizard.validation.event_not_sponsorable')]);
        }

        return Sponsorship::query()->create([
            'event_id' => $event->getKey(),
            'city_id' => $event->city_id,
            'placement' => $placement,
            'status' => $active ? SponsorshipStatus::Active : SponsorshipStatus::Draft,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'advertiser_name' => $data['advertiser_name'],
            'advertiser_email' => $data['advertiser_email'] ?? null,
            'advertiser_url' => $data['advertiser_url'] ?? null,
            'amount_cents' => $complimentary ? null : ($data['amount_cents'] ?? null),
            'currency' => 'EUR',
            'invoice_reference' => $complimentary ? null : ($data['invoice_reference'] ?? null),
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
            ...$delivery,
        ]);
    }

    // ───────────────────────────── Passo 1 ─────────────────────────────

    private static function sponsorStep(): Step
    {
        return Step::make(__('sponsorships.wizard.steps.sponsor'))
            ->description(__('sponsorships.wizard.steps.sponsor_lead'))
            ->icon(Heroicon::OutlinedUser)
            ->schema([
                Radio::make('sponsor_type')
                    ->label(__('sponsorships.wizard.fields.sponsor_type'))
                    ->options([
                        self::SPONSOR_VENUE => __('sponsorships.wizard.sponsor_types.venue'),
                        self::SPONSOR_ORGANIZER => __('sponsorships.wizard.sponsor_types.organizer'),
                        self::SPONSOR_EXTERNAL => __('sponsorships.wizard.sponsor_types.external'),
                    ])
                    ->descriptions([
                        self::SPONSOR_VENUE => __('sponsorships.wizard.sponsor_types.venue_lead'),
                        self::SPONSOR_ORGANIZER => __('sponsorships.wizard.sponsor_types.organizer_lead'),
                        self::SPONSOR_EXTERNAL => __('sponsorships.wizard.sponsor_types.external_lead'),
                    ])
                    ->default(fn (): string => self::prefillType())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        $set('venue_id', null);
                        $set('organizer_id', null);
                        $set('event_id', null);
                        $set('venue_filter', null);
                        self::fillAdvertiser($get, $set);
                        self::syncCity($get, $set);
                    }),

                Select::make('venue_id')
                    ->label(__('sponsorships.wizard.fields.venue'))
                    ->placeholder(__('sponsorships.wizard.placeholders.venue'))
                    ->searchable()
                    ->options(fn (): array => self::venueOptions())
                    ->getSearchResultsUsing(fn (?string $search): array => self::venueOptions($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::venueOptions(null, $value)[$value] ?? null)
                    ->searchPrompt(__('sponsorships.wizard.placeholders.search_venue'))
                    ->noSearchResultsMessage(__('sponsorships.wizard.placeholders.no_venue'))
                    ->default(fn (): ?int => self::prefillType() === self::SPONSOR_VENUE ? self::prefillEvent()?->venue_id : null)
                    ->visible(fn (Get $get): bool => $get('sponsor_type') === self::SPONSOR_VENUE)
                    ->required(fn (Get $get): bool => $get('sponsor_type') === self::SPONSOR_VENUE)
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        $set('event_id', null);
                        self::fillAdvertiser($get, $set);
                        self::syncCity($get, $set);
                    }),

                Select::make('organizer_id')
                    ->label(__('sponsorships.wizard.fields.organizer'))
                    ->placeholder(__('sponsorships.wizard.placeholders.organizer'))
                    ->searchable()
                    ->options(fn (): array => self::organizerOptions())
                    ->getSearchResultsUsing(fn (?string $search): array => self::organizerOptions($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::organizerOptions(null, $value)[$value] ?? null)
                    ->searchPrompt(__('sponsorships.wizard.placeholders.search_organizer'))
                    ->noSearchResultsMessage(__('sponsorships.wizard.placeholders.no_organizer'))
                    ->default(fn (): ?int => self::prefillType() === self::SPONSOR_ORGANIZER ? self::prefillEvent()?->organizer_id : null)
                    ->visible(fn (Get $get): bool => $get('sponsor_type') === self::SPONSOR_ORGANIZER)
                    ->required(fn (Get $get): bool => $get('sponsor_type') === self::SPONSOR_ORGANIZER)
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        $set('event_id', null);
                        self::fillAdvertiser($get, $set);
                        self::syncCity($get, $set);
                    }),

                Section::make(__('sponsorships.wizard.sections.advertiser'))
                    ->description(__('sponsorships.wizard.sections.advertiser_lead'))
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema([
                        TextInput::make('advertiser_name')
                            ->label(__('sponsorships.admin.fields.advertiser_name'))
                            ->helperText(__('sponsorships.admin.help.advertiser_name'))
                            ->default(fn (): ?string => self::prefillSponsor()?->name)
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('advertiser_email')
                            ->label(__('sponsorships.admin.fields.advertiser_email'))
                            ->default(fn (): ?string => self::sponsorEmail(self::prefillSponsor()))
                            ->email()
                            ->maxLength(255),

                        TextInput::make('advertiser_url')
                            ->label(__('sponsorships.admin.fields.advertiser_url'))
                            ->default(fn (): ?string => self::prefillSponsor()?->website)
                            ->url()
                            ->maxLength(255),
                    ]),
            ]);
    }

    // ───────────────────────────── Passo 2 ─────────────────────────────

    private static function whatStep(): Step
    {
        return Step::make(__('sponsorships.wizard.steps.what'))
            ->description(__('sponsorships.wizard.steps.what_lead'))
            ->icon(Heroicon::OutlinedTicket)
            ->schema([
                Radio::make('scope')
                    ->label(__('sponsorships.wizard.fields.scope'))
                    ->options([
                        self::SCOPE_EVENT => __('sponsorships.wizard.scopes.event'),
                        self::SCOPE_ALL => __('sponsorships.wizard.scopes.all'),
                    ])
                    ->descriptions([
                        self::SCOPE_EVENT => __('sponsorships.wizard.scopes.event_lead'),
                        self::SCOPE_ALL => __('sponsorships.wizard.scopes.all_lead'),
                    ])
                    ->default(self::SCOPE_EVENT)
                    ->visible(fn (Get $get): bool => $get('sponsor_type') !== self::SPONSOR_EXTERNAL)
                    ->required(fn (Get $get): bool => $get('sponsor_type') !== self::SPONSOR_EXTERNAL)
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::syncCity($get, $set)),

                Select::make('venue_filter')
                    ->label(__('sponsorships.wizard.fields.venue_filter'))
                    ->placeholder(__('sponsorships.wizard.placeholders.venue_filter'))
                    ->helperText(__('sponsorships.wizard.help.venue_filter'))
                    ->searchable()
                    ->options(fn (): array => self::venueOptions(null, null, approvedOnly: false))
                    ->getSearchResultsUsing(fn (?string $search): array => self::venueOptions($search, null, approvedOnly: false))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::venueOptions(null, $value, approvedOnly: false)[$value] ?? null)
                    ->visible(fn (Get $get): bool => $get('sponsor_type') === self::SPONSOR_EXTERNAL)
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('event_id', null)),

                EventPicker::make('event_id', fn (Get $get): ?Builder => self::eventsQuery($get))
                    ->default(fn (): ?int => self::prefillEvent()?->getKey())
                    ->helperText(fn (Get $get): string => $get('sponsor_type') === self::SPONSOR_EXTERNAL
                        ? __('sponsorships.wizard.help.event_external')
                        : __('sponsorships.wizard.help.event_sponsor'))
                    ->visible(fn (Get $get): bool => self::scope($get) === self::SCOPE_EVENT)
                    ->required(fn (Get $get): bool => self::scope($get) === self::SCOPE_EVENT)
                    ->validationMessages(['in' => __('sponsorships.wizard.validation.event_not_sponsorable')])
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::syncCity($get, $set)),

                View::make('filament.forms.components.event-picker')
                    ->viewData(fn (Get $get): array => self::allEventsData($get))
                    ->visible(fn (Get $get): bool => self::scope($get) === self::SCOPE_ALL),

                Hidden::make('city_id')
                    ->default(fn (): ?int => self::prefillEvent()?->city_id),
            ]);
    }

    // ───────────────────────────── Passo 3 ─────────────────────────────

    private static function whereStep(): Step
    {
        return Step::make(__('sponsorships.wizard.steps.where'))
            ->description(__('sponsorships.wizard.steps.where_lead'))
            ->icon(Heroicon::OutlinedMapPin)
            ->schema([
                Radio::make('placement')
                    ->label(__('sponsorships.admin.fields.placement'))
                    ->options(SponsorshipPlacement::options())
                    ->descriptions(collect(SponsorshipPlacement::cases())
                        ->mapWithKeys(fn (SponsorshipPlacement $placement): array => [$placement->value => __('promotions.formats.'.$placement->value)])
                        ->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        if (self::periodOnlyMessage($get('placement')) !== null && $get('product') === self::PRODUCT_VIEWS) {
                            $set('product', self::PRODUCT_PERIOD);
                        }

                        self::proposeAmount($get, $set);
                    }),

                View::make('filament.admin.sponsorships.placement-preview')
                    ->viewData(fn (Get $get): array => ['placement' => $get('placement')])
                    ->visible(fn (Get $get): bool => filled($get('placement'))),

                Section::make(__('sponsorships.wizard.sections.period'))
                    ->description(fn (Get $get): string => __('sponsorships.wizard.sections.period_lead', ['timezone' => self::timezone($get('city_id'))]))
                    ->columns(['default' => 1, 'lg' => 3])
                    ->schema([
                        DateTimePicker::make('starts_at')
                            ->label(__('sponsorships.admin.fields.starts_at'))
                            ->seconds(false)
                            ->required()
                            ->default(fn (): string => CarbonImmutable::now('UTC')->startOfHour()->setTimezone(config()->string('app.timezone'))->format('Y-m-d H:i:s'))
                            ->timezone(fn (Get $get): string => self::timezone($get('city_id')))
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateEndDate($get, $set)),

                        Select::make('months')
                            ->label(__('sponsorships.wizard.fields.months'))
                            ->options(collect(self::MONTHS)->mapWithKeys(fn (int $m): array => [$m => trans_choice('sponsorships.wizard.months', $m, ['count' => $m])])->all())
                            ->placeholder(__('sponsorships.wizard.placeholders.custom_duration'))
                            ->default(1)
                            ->dehydrated(false)
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                self::updateEndDate($get, $set);
                                self::proposeAmount($get, $set);
                            }),

                        DateTimePicker::make('ends_at')
                            ->label(__('sponsorships.admin.fields.ends_at'))
                            ->seconds(false)
                            ->required()
                            ->after('starts_at')
                            ->validationMessages(['after' => __('sponsorships.admin.validation.ends_after_starts')])
                            ->default(fn (): string => CarbonImmutable::now('UTC')->startOfHour()->addMonthNoOverflow()->setTimezone(config()->string('app.timezone'))->format('Y-m-d H:i:s'))
                            ->timezone(fn (Get $get): string => self::timezone($get('city_id')))
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set) => $set('months', null)),
                    ]),

                Section::make(__('sponsorships.wizard.sections.product'))
                    ->schema([
                        Radio::make('product')
                            ->label(__('sponsorships.wizard.fields.product'))
                            ->options([
                                self::PRODUCT_PERIOD => __('sponsorships.wizard.products.period'),
                                self::PRODUCT_VIEWS => __('sponsorships.wizard.products.views'),
                            ])
                            ->descriptions([
                                self::PRODUCT_PERIOD => __('sponsorships.wizard.products.period_lead'),
                                self::PRODUCT_VIEWS => __('sponsorships.wizard.products.views_lead'),
                            ])
                            ->default(self::PRODUCT_PERIOD)
                            ->disableOptionWhen(fn (string $value, Get $get): bool => $value === self::PRODUCT_VIEWS && self::periodOnlyMessage($get('placement')) !== null)
                            ->helperText(fn (Get $get): ?string => self::periodOnlyMessage($get('placement')))
                            ->required()
                            ->live(),

                        Grid::make(['default' => 1, 'lg' => 2])
                            ->visible(fn (Get $get): bool => $get('product') === self::PRODUCT_VIEWS)
                            ->schema([
                                TextInput::make('impressions_goal')
                                    ->label(__('sponsorships.wizard.fields.goal'))
                                    ->helperText(__('sponsorships.wizard.help.goal'))
                                    ->integer()
                                    ->minValue(1)
                                    ->maxValue(10_000_000)
                                    ->required(fn (Get $get): bool => $get('product') === self::PRODUCT_VIEWS)
                                    ->rule(fn (Get $get): Closure => self::goalRule($get))
                                    ->live(onBlur: true),

                                Radio::make('pacing')
                                    ->label(__('sponsorships.wizard.fields.pacing'))
                                    ->options(self::pacingOptions())
                                    ->descriptions([
                                        SponsorshipPacing::Even->value => __('sponsorships.pacing.even_lead'),
                                        SponsorshipPacing::Asap->value => __('sponsorships.pacing.asap_lead'),
                                    ])
                                    ->default(SponsorshipPacing::Even->value)
                                    ->required(fn (Get $get): bool => $get('product') === self::PRODUCT_VIEWS),
                            ]),

                        Callout::make(fn (Get $get): ?string => self::estimateHeading($get))
                            ->description(fn (Get $get): ?string => self::estimateDescription($get))
                            ->status(fn (Get $get): string => self::estimateStatus($get))
                            ->visible(fn (Get $get): bool => self::estimateFor($get) !== null),
                    ]),

                View::make('filament.admin.sponsorships.occupants')
                    ->viewData(fn (Get $get): array => self::occupantsData($get)),
            ]);
    }

    // ───────────────────────────── Passo 4 ─────────────────────────────

    private static function paymentStep(): Step
    {
        return Step::make(__('sponsorships.wizard.steps.payment'))
            ->description(__('sponsorships.wizard.steps.payment_lead'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->schema([
                Section::make(__('sponsorships.wizard.sections.payment'))
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema([
                        Toggle::make('complimentary')
                            ->label(__('sponsorships.wizard.fields.complimentary'))
                            ->helperText(__('sponsorships.wizard.help.complimentary'))
                            ->live()
                            ->columnSpanFull(),

                        TextInput::make('amount_cents')
                            ->label(__('sponsorships.admin.fields.amount'))
                            ->prefix('€')
                            ->numeric()
                            ->step(0.01)
                            ->rules(['decimal:0,2'])
                            ->minValue(0.01)
                            ->maxValue(1_000_000)
                            ->helperText(fn (Get $get): string => self::priceHint($get))
                            ->dehydrateStateUsing(fn (mixed $state): ?int => filled($state) && is_numeric($state) ? (int) round((float) $state * 100) : null)
                            ->required(fn (Get $get): bool => ! $get('complimentary'))
                            ->visible(fn (Get $get): bool => ! $get('complimentary')),

                        TextInput::make('invoice_reference')
                            ->label(__('sponsorships.admin.fields.invoice_reference'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => ! $get('complimentary') && self::scope($get) === self::SCOPE_EVENT),

                        DateTimePicker::make('paid_at')
                            ->label(__('promotions.paid'))
                            ->seconds(false)
                            ->maxDate(fn () => now())
                            ->timezone(fn (Get $get): string => self::timezone($get('city_id')))
                            ->helperText(__('sponsorships.wizard.help.paid_at'))
                            ->visible(fn (Get $get): bool => ! $get('complimentary') && self::scope($get) === self::SCOPE_ALL),

                        TextInput::make('payment_method')
                            ->label(__('promotions.method'))
                            ->maxLength(100)
                            ->visible(fn (Get $get): bool => ! $get('complimentary') && self::scope($get) === self::SCOPE_ALL),

                        TextInput::make('payment_reference')
                            ->label(__('promotions.reference'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => ! $get('complimentary') && self::scope($get) === self::SCOPE_ALL),

                        Textarea::make('notes')
                            ->label(fn (Get $get): string => $get('complimentary') ? __('sponsorships.wizard.fields.complimentary_reason') : __('sponsorships.admin.fields.notes'))
                            ->required(fn (Get $get): bool => (bool) $get('complimentary'))
                            ->maxLength(5000)
                            ->rows(3)
                            ->columnSpanFull(),

                        Radio::make('status')
                            ->label(__('sponsorships.wizard.fields.status'))
                            ->options([
                                SponsorshipStatus::Active->value => __('sponsorships.wizard.statuses.active'),
                                SponsorshipStatus::Draft->value => __('sponsorships.wizard.statuses.draft'),
                            ])
                            ->default(SponsorshipStatus::Active->value)
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make(__('sponsorships.wizard.sections.advanced'))
                    ->description(__('sponsorships.wizard.sections.advanced_lead'))
                    ->collapsible()
                    ->collapsed()
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema([
                        TextInput::make('priority')
                            ->label(__('sponsorships.admin.fields.priority'))
                            ->helperText(__('sponsorships.admin.help.priority'))
                            ->integer()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(1000)
                            ->required(),

                        TextInput::make('weight')
                            ->label(__('sponsorships.admin.fields.weight'))
                            ->helperText(__('sponsorships.admin.help.weight'))
                            ->integer()
                            ->default(1)
                            ->minValue(1)
                            ->maxValue(100)
                            ->required(),
                    ]),

                View::make('filament.admin.sponsorships.summary')
                    ->viewData(fn (Get $get): array => self::summaryData($get)),
            ]);
    }

    // ─────────────────────────── Aiuti del modulo ───────────────────────────

    /** @return array<string, string> */
    public static function pacingOptions(): array
    {
        return [
            SponsorshipPacing::Even->value => __('sponsorships.pacing.even'),
            SponsorshipPacing::Asap->value => __('sponsorships.pacing.asap'),
        ];
    }

    /**
     * I locali, per nome. Quelli approvati per un committente: un locale
     * sospeso o in attesa non può comparire, quindi non può nemmeno pagare.
     *
     * @return array<int|string, string>
     */
    public static function venueOptions(?string $search = null, mixed $value = null, bool $approvedOnly = true): array
    {
        $query = Venue::query()
            ->when($approvedOnly, fn (Builder $q) => $q->where('status', VenueStatus::Approved))
            ->with('city:id,name')
            ->orderBy('name');

        if ($value !== null) {
            if (blank($value) || ! is_scalar($value)) {
                return [];
            }

            $query->whereKey($value);
        } elseif (filled($search)) {
            $query->where('name', 'like', '%'.addcslashes(trim((string) $search), '%_\\').'%');
        }

        $multipleCities = City::timezones()->count() > 1;

        return $query->limit(50)->get(['id', 'name', 'city_id'])
            ->mapWithKeys(fn (Venue $venue): array => [$venue->getKey() => $multipleCities && $venue->city !== null
                ? $venue->name.' · '.$venue->city->name
                : (string) $venue->name])
            ->all();
    }

    /** @return array<int|string, string> */
    public static function organizerOptions(?string $search = null, mixed $value = null): array
    {
        $query = Organizer::query()->where('is_active', true)->orderBy('name');

        if ($value !== null) {
            if (blank($value) || ! is_scalar($value)) {
                return [];
            }

            $query->whereKey($value);
        } elseif (filled($search)) {
            $query->where('name', 'like', '%'.addcslashes(trim((string) $search), '%_\\').'%');
        }

        return $query->limit(50)->pluck('name', 'id')->map(fn (mixed $name): string => (string) $name)->all();
    }

    /**
     * Perché questa collocazione non si vende a visualizzazioni, oppure
     * `null` se si può.
     */
    public static function periodOnlyMessage(mixed $placement): ?string
    {
        $placement = $placement instanceof SponsorshipPlacement ? $placement : SponsorshipPlacement::tryFrom(is_string($placement) ? $placement : '');

        return match ($placement) {
            SponsorshipPlacement::HomeHero => __('sponsorships.wizard.help.hero_period_only'),
            SponsorshipPlacement::MapSheet => __('sponsorships.wizard.help.map_period_only'),
            default => null,
        };
    }

    /** Il fuso della città scelta, per i selettori di data. */
    public static function timezone(mixed $cityId): string
    {
        $timezone = is_numeric($cityId) ? City::query()->whereKey((int) $cityId)->value('timezone') : null;

        return is_string($timezone) && $timezone !== '' ? $timezone : config()->string('app.timezone');
    }

    /**
     * Il valore di un selettore di data come lo restituisce `Get`, come
     * istante. Livewire tiene l'ora mostrata (quella della città), ma `Get`
     * passa dal cast di stato del selettore e restituisce l'ora
     * dell'applicazione; `Set` fa la conversione inversa.
     */
    public static function instant(mixed $state): ?CarbonImmutable
    {
        if (! is_string($state) || blank($state)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($state, config()->string('app.timezone'))->utc();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /** @return array{views: int, days: int, provisional: bool}|null */
    public static function estimateFor(Get $get): ?array
    {
        $placement = SponsorshipPlacement::tryFrom((string) $get('placement'));
        $city = is_numeric($get('city_id')) ? City::query()->find((int) $get('city_id')) : null;

        /* Per «un evento» la stima si ferma alla sua ultima data, come il motore. */
        $lastCovered = self::scope($get) === self::SCOPE_EVENT && is_numeric($get('event_id'))
            ? SponsorshipCapacity::lastCoveredFor((int) $get('event_id'), $placement)
            : null;

        return app(SponsorshipCapacity::class)->estimate($city, $placement, self::instant($get('starts_at')), self::instant($get('ends_at')), lastCovered: $lastCovered);
    }

    private static function scope(Get $get): string
    {
        return $get('sponsor_type') === self::SPONSOR_EXTERNAL ? self::SCOPE_EVENT : (string) ($get('scope') ?? self::SCOPE_EVENT);
    }

    /** @return Builder<Event>|null */
    private static function eventsQuery(Get $get): ?Builder
    {
        return match ($get('sponsor_type')) {
            self::SPONSOR_VENUE => filled($get('venue_id')) ? Event::query()->where('events.venue_id', $get('venue_id')) : null,
            self::SPONSOR_ORGANIZER => filled($get('organizer_id')) ? Event::query()->where('events.organizer_id', $get('organizer_id')) : null,
            self::SPONSOR_EXTERNAL => Event::query()->when(filled($get('venue_filter')), fn (Builder $q) => $q->where('events.venue_id', $get('venue_filter'))),
            default => null,
        };
    }

    private static function sponsor(string $type, mixed $venueId, mixed $organizerId): Venue|Organizer|null
    {
        return match ($type) {
            self::SPONSOR_VENUE => is_numeric($venueId) ? Venue::query()->find((int) $venueId) : null,
            self::SPONSOR_ORGANIZER => is_numeric($organizerId) ? Organizer::query()->find((int) $organizerId) : null,
            default => null,
        };
    }

    private static function sponsorFromState(Get $get): Venue|Organizer|null
    {
        return self::sponsor((string) $get('sponsor_type'), $get('venue_id'), $get('organizer_id'));
    }

    private static function sponsorEmail(Venue|Organizer|null $sponsor): ?string
    {
        if ($sponsor === null) {
            return null;
        }

        $email = $sponsor->email ?: $sponsor->contact_email;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /** Nome e contatti del committente si compilano dal locale o dall'organizzatore scelto. */
    private static function fillAdvertiser(Get $get, Set $set): void
    {
        $sponsor = self::sponsorFromState($get);

        if ($sponsor === null) {
            if ($get('sponsor_type') === self::SPONSOR_EXTERNAL) {
                $set('advertiser_name', null);
                $set('advertiser_email', null);
                $set('advertiser_url', null);
            }

            return;
        }

        $set('advertiser_name', $sponsor->name);
        $set('advertiser_email', self::sponsorEmail($sponsor));
        $set('advertiser_url', $sponsor->website ?: null);
    }

    /**
     * La città della campagna, che dà il fuso alle date: quella dell'evento
     * scelto, oppure quella del committente per «tutti gli eventi».
     */
    private static function syncCity(Get $get, Set $set): void
    {
        if (self::scope($get) === self::SCOPE_EVENT) {
            $cityId = filled($get('event_id')) ? Event::query()->whereKey($get('event_id'))->value('city_id') : null;
        } else {
            $cityId = self::sponsorFromState($get)?->city_id;
        }

        $cityId = $cityId === null ? null : (int) $cityId;

        if ($cityId === (is_numeric($get('city_id')) ? (int) $get('city_id') : null)) {
            return;
        }

        /*
         * Le date seguono la città: Livewire tiene l'ora mostrata, quindi
         * cambiando fuso le stesse cifre vorrebbero dire un altro istante. Le
         * leggo prima (ora dell'applicazione) e le riscrivo dopo, così restano
         * lo stesso istante mostrato nel fuso nuovo.
         */
        $starts = $get('starts_at');
        $ends = $get('ends_at');
        $set('city_id', $cityId);
        $set('starts_at', $starts);
        $set('ends_at', $ends);
    }

    private static function updateEndDate(Get $get, Set $set): void
    {
        $months = filter_var($get('months'), FILTER_VALIDATE_INT);
        $timezone = self::timezone($get('city_id'));
        $start = self::instant($get('starts_at'));

        if (! in_array($months, self::MONTHS, true) || $start === null) {
            return;
        }

        /* I mesi si contano nel fuso della città: il 31 gennaio più un mese è
           il 28 febbraio lì, non in UTC. `Set` vuole l'ora dell'applicazione. */
        $set('ends_at', $start->setTimezone($timezone)
            ->addMonthsNoOverflow($months)
            ->setTimezone(config()->string('app.timezone'))
            ->format('Y-m-d H:i:s'));
    }

    private static function listPrice(Get $get): ?SponsorshipPrice
    {
        $placement = SponsorshipPlacement::tryFrom((string) $get('placement'));
        $months = filter_var($get('months'), FILTER_VALIDATE_INT);

        if ($placement === null || $months === false) {
            return null;
        }

        return SponsorshipPrice::query()->active()->where('placement', $placement)->where('months', $months)->first();
    }

    /** L'importo proposto dal listino quando cambiano collocazione o durata. */
    private static function proposeAmount(Get $get, Set $set): void
    {
        $price = self::listPrice($get);

        if ($price !== null) {
            $set('amount_cents', number_format($price->amount_cents / 100, 2, '.', ''));
        }
    }

    private static function priceHint(Get $get): string
    {
        $price = self::listPrice($get);

        if ($price !== null) {
            return __('sponsorships.wizard.help.price_from_list', ['price' => $price->formattedAmount(), 'months' => trans_choice('sponsorships.wizard.months', $price->months, ['count' => $price->months])]);
        }

        return __('sponsorships.wizard.help.price_none');
    }

    /** La regola dell'obiettivo: oltre l'80% della stima (`quota_max`) non si salva. */
    private static function goalRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            if ($get('product') !== self::PRODUCT_VIEWS || ! is_numeric($value)) {
                return;
            }

            $periodOnly = self::periodOnlyMessage($get('placement'));

            if ($periodOnly !== null) {
                $fail($periodOnly);

                return;
            }

            $estimate = self::estimateFor($get);

            if ($estimate !== null && SponsorshipCapacity::verdict((int) $value, $estimate['views']) === 'blocked') {
                $fail(__('sponsorships.wizard.validation.goal_blocked', [
                    'estimate' => number_format($estimate['views'], 0, ',', '.'),
                    'max' => number_format((int) floor($estimate['views'] * SponsorshipCapacity::blockShare()), 0, ',', '.'),
                ]));
            }
        };
    }

    private static function estimateHeading(Get $get): ?string
    {
        $estimate = self::estimateFor($get);

        if ($estimate === null) {
            return null;
        }

        return __('sponsorships.wizard.estimate.heading', ['views' => number_format($estimate['views'], 0, ',', '.')]);
    }

    private static function estimateDescription(Get $get): ?string
    {
        $estimate = self::estimateFor($get);

        if ($estimate === null) {
            return null;
        }

        $parts = [$estimate['days'] > 0
            ? trans_choice('sponsorships.wizard.estimate.days', $estimate['days'], ['count' => $estimate['days']])
            : __('sponsorships.wizard.estimate.no_data')];

        if ($estimate['provisional']) {
            $parts[] = __('sponsorships.wizard.estimate.provisional');
        }

        $goal = $get('product') === self::PRODUCT_VIEWS && is_numeric($get('impressions_goal')) ? (int) $get('impressions_goal') : null;

        if ($goal !== null) {
            $parts[] = match (SponsorshipCapacity::verdict($goal, $estimate['views'])) {
                'blocked' => __('sponsorships.wizard.estimate.blocked'),
                'warning' => __('sponsorships.wizard.estimate.warning', ['share' => number_format($estimate['views'] > 0 ? $goal / $estimate['views'] * 100 : 0, 0, ',', '.')]),
                default => __('sponsorships.wizard.estimate.ok'),
            };
        }

        return implode(' ', $parts);
    }

    private static function estimateStatus(Get $get): string
    {
        $estimate = self::estimateFor($get);
        $goal = is_numeric($get('impressions_goal')) ? (int) $get('impressions_goal') : null;

        if ($estimate === null || $goal === null || $get('product') !== self::PRODUCT_VIEWS) {
            return 'info';
        }

        return match (SponsorshipCapacity::verdict($goal, $estimate['views'])) {
            'blocked' => 'danger',
            'warning' => 'warning',
            default => 'success',
        };
    }

    /** @return array<string, mixed> */
    private static function occupantsData(Get $get): array
    {
        $placement = SponsorshipPlacement::tryFrom((string) $get('placement'));
        $city = is_numeric($get('city_id')) ? City::query()->find((int) $get('city_id')) : null;
        $start = self::instant($get('starts_at'));
        $end = self::instant($get('ends_at'));

        return [
            'ready' => $placement !== null && $city !== null && $start !== null && $end !== null,
            'placement' => $placement,
            'timezone' => self::timezone($get('city_id')),
            'occupants' => app(SponsorshipCapacity::class)->occupants($city, $placement, $start, $end),
        ];
    }

    /** @return array<string, mixed> */
    private static function allEventsData(Get $get): array
    {
        $query = self::eventsQuery($get);
        $sponsor = self::sponsorFromState($get);

        return [
            'sponsor' => $sponsor?->name,
            'count' => $query === null ? 0 : EventPicker::count($query),
            'events' => $query === null ? [] : EventPicker::preview($query),
        ];
    }

    /** @return array<string, mixed> */
    private static function summaryData(Get $get): array
    {
        $scope = self::scope($get);
        $sponsor = self::sponsorFromState($get);
        $event = $scope === self::SCOPE_EVENT && filled($get('event_id'))
            ? Event::query()->with(['venue:id,name', 'city:id,timezone', 'media'])->find($get('event_id'))
            : null;
        $placement = SponsorshipPlacement::tryFrom((string) $get('placement'));
        $complimentary = (bool) $get('complimentary');
        $amount = is_numeric($get('amount_cents')) ? (float) $get('amount_cents') : null;

        return [
            'scope' => $scope,
            'sponsorType' => (string) $get('sponsor_type'),
            'sponsor' => $sponsor?->name,
            'advertiser' => $get('advertiser_name'),
            'event' => $event,
            'eventsCount' => $scope === self::SCOPE_ALL && ($query = self::eventsQuery($get)) !== null ? EventPicker::count($query) : null,
            'placement' => $placement?->label(),
            'starts' => self::instant($get('starts_at')),
            'ends' => self::instant($get('ends_at')),
            'timezone' => self::timezone($get('city_id')),
            'views' => $get('product') === self::PRODUCT_VIEWS,
            'goal' => is_numeric($get('impressions_goal')) ? (int) $get('impressions_goal') : null,
            'pacing' => self::pacingOptions()[(string) $get('pacing')] ?? null,
            'complimentary' => $complimentary,
            'amount' => $amount,
            'paid' => self::instant($get('paid_at')) !== null,
            'active' => $get('status') === SponsorshipStatus::Active->value,
            'priority' => (int) ($get('priority') ?? 0),
            'weight' => (int) ($get('weight') ?? 1),
        ];
    }

    private static function intOr(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    // ─────────────────── Arrivo da «Sponsorizza questo evento» ───────────────────

    /** L'evento passato con `?event=ID` dalla scheda in redazione. */
    private static function prefillEvent(): ?Event
    {
        $id = request()->integer('event');

        return $id > 0 ? once(fn (): ?Event => Event::query()->find($id)) : null;
    }

    private static function prefillType(): string
    {
        $event = self::prefillEvent();

        return match (true) {
            $event === null, $event->venue_id !== null => self::SPONSOR_VENUE,
            $event->organizer_id !== null => self::SPONSOR_ORGANIZER,
            default => self::SPONSOR_EXTERNAL,
        };
    }

    private static function prefillSponsor(): Venue|Organizer|null
    {
        $event = self::prefillEvent();

        if ($event === null) {
            return null;
        }

        return match (self::prefillType()) {
            self::SPONSOR_VENUE => $event->venue,
            self::SPONSOR_ORGANIZER => $event->organizer,
            default => null,
        };
    }
}
