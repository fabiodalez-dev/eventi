<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Editorial;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipPacing;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\VenueStatus;
use App\Models\City;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\SponsorshipPrice;
use App\Models\Venue;
use App\Services\Sponsorship\GrantCampaigns;
use App\Services\Sponsorship\GrantPayment;
use App\Support\Sponsorship\SponsorableEventChoices;
use App\Support\Sponsorship\SponsorableEvents;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «Nuova sponsorizzazione» della redazione, senza lo schermo: il salvataggio,
 * la stima di quanto lo spazio può dare, chi lo occupa già, il prezzo dal
 * listino, i committenti e gli eventi fra cui scegliere, e le regole di ogni
 * passo.
 *
 * Lo chiamano il percorso in quattro passi del pannello
 * (`SponsorshipWizard`, che ci aggiunge solo i campi e le loro reazioni) e
 * l'API dell'app (R8), così le due strade salvano e rifiutano le stesse cose.
 *
 * I quattro passi: 1. committente (locale, organizzatore o esterno, con nome
 * e contatti di chi paga); 2. cosa (un evento sponsorizzabile del
 * committente, o «tutti gli eventi» per locale e organizzatore); 3. dove e
 * quanto (collocazione, periodo nel fuso della città, prodotto a periodo o a
 * visualizzazioni); 4. pagamento (importo o gratuità con motivo, stato,
 * priorità e peso).
 */
final class CampaignBuilder
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

    /** Le voci dell'anteprima di «tutti gli eventi». */
    public const PREVIEW_LIMIT = 5;

    public function __construct(private readonly SponsorshipCapacity $capacity) {}

    /**
     * Salva ciò che il percorso ha raccolto: una campagna singola oppure
     * un'abilitazione automatica con le sue righe. `$errorPrefix` è il
     * prefisso dei campi negli errori (`data.` nel pannello, niente nell'API).
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data, ?int $userId, string $errorPrefix = 'data.'): Sponsorship|SponsorshipGrant
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
                throw ValidationException::withMessages([$errorPrefix.'venue_id' => __('sponsorships.wizard.validation.sponsor_required')]);
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
            throw ValidationException::withMessages([$errorPrefix.'event_id' => __('sponsorships.wizard.validation.event_not_sponsorable')]);
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

    // ───────────────────────────── Le scelte ─────────────────────────────

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
        $multipleCities = City::timezones()->count() > 1;

        return self::venues($search, $value, $approvedOnly)
            ->mapWithKeys(fn (Venue $venue): array => [$venue->getKey() => $multipleCities && $venue->city !== null
                ? $venue->name.' · '.$venue->city->name
                : (string) $venue->name])
            ->all();
    }

    /**
     * I locali della scelta, con nome, città e contatti per compilare chi paga.
     *
     * @return Collection<int, Venue>
     */
    public static function venues(?string $search = null, mixed $value = null, bool $approvedOnly = true, int $limit = 50): Collection
    {
        $query = Venue::query()
            ->when($approvedOnly, fn (Builder $q) => $q->where('status', VenueStatus::Approved))
            ->with('city:id,name')
            ->orderBy('name');

        if ($value !== null) {
            if (blank($value) || ! is_scalar($value)) {
                return new Collection;
            }

            $query->whereKey($value);
        } elseif (filled($search)) {
            $query->where('name', 'like', '%'.addcslashes(trim((string) $search), '%_\\').'%');
        }

        return $query->limit($limit)->get(['id', 'name', 'city_id', 'email', 'contact_email', 'website']);
    }

    /** @return array<int|string, string> */
    public static function organizerOptions(?string $search = null, mixed $value = null): array
    {
        return self::organizers($search, $value)->mapWithKeys(fn (Organizer $organizer): array => [$organizer->getKey() => (string) $organizer->name])->all();
    }

    /**
     * Gli organizzatori della scelta: solo quelli attivi per un committente.
     *
     * @return Collection<int, Organizer>
     */
    public static function organizers(?string $search = null, mixed $value = null, bool $activeOnly = true, int $limit = 50): Collection
    {
        $query = Organizer::query()->when($activeOnly, fn (Builder $q) => $q->where('is_active', true))->orderBy('name');

        if ($value !== null) {
            if (blank($value) || ! is_scalar($value)) {
                return new Collection;
            }

            $query->whereKey($value);
        } elseif (filled($search)) {
            $query->where('name', 'like', '%'.addcslashes(trim((string) $search), '%_\\').'%');
        }

        return $query->limit($limit)->get(['id', 'name', 'city_id', 'email', 'contact_email', 'website']);
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

    /** Il fuso della città scelta, per le date. */
    public static function timezone(mixed $cityId): string
    {
        $timezone = is_numeric($cityId) ? City::query()->whereKey((int) $cityId)->value('timezone') : null;

        return is_string($timezone) && $timezone !== '' ? $timezone : config()->string('app.timezone');
    }

    /** «Un evento» è l'unica scelta di un committente esterno. */
    public static function scopeOf(mixed $sponsorType, mixed $scope): string
    {
        return $sponsorType === self::SPONSOR_EXTERNAL ? self::SCOPE_EVENT : (is_string($scope) && $scope !== '' ? $scope : self::SCOPE_EVENT);
    }

    /**
     * Gli eventi fra cui scegliere, prima della regola degli sponsorizzabili:
     * quelli del locale o dell'organizzatore, oppure tutti (con un locale per
     * filtrare) per un committente esterno. `null` finché manca il committente.
     *
     * @return Builder<Event>|null
     */
    public static function eventsQuery(mixed $sponsorType, mixed $venueId, mixed $organizerId, mixed $venueFilter = null): ?Builder
    {
        return match ($sponsorType) {
            self::SPONSOR_VENUE => filled($venueId) ? Event::query()->where('events.venue_id', $venueId) : null,
            self::SPONSOR_ORGANIZER => filled($organizerId) ? Event::query()->where('events.organizer_id', $organizerId) : null,
            self::SPONSOR_EXTERNAL => Event::query()->when(filled($venueFilter), fn (Builder $q) => $q->where('events.venue_id', $venueFilter)),
            default => null,
        };
    }

    public static function sponsor(string $type, mixed $venueId, mixed $organizerId): Venue|Organizer|null
    {
        return match ($type) {
            self::SPONSOR_VENUE => is_numeric($venueId) ? Venue::query()->find((int) $venueId) : null,
            self::SPONSOR_ORGANIZER => is_numeric($organizerId) ? Organizer::query()->find((int) $organizerId) : null,
            default => null,
        };
    }

    public static function sponsorEmail(Venue|Organizer|null $sponsor): ?string
    {
        if ($sponsor === null) {
            return null;
        }

        $email = $sponsor->email ?: $sponsor->contact_email;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * La città della campagna, che dà il fuso alle date: quella dell'evento
     * scelto, oppure quella del committente per «tutti gli eventi».
     */
    public static function cityIdFor(string $scope, mixed $eventId, Venue|Organizer|null $sponsor): ?int
    {
        $cityId = $scope === self::SCOPE_EVENT
            ? (filled($eventId) && is_scalar($eventId) ? Event::query()->whereKey($eventId)->value('city_id') : null)
            : $sponsor?->city_id;

        return is_numeric($cityId) ? (int) $cityId : null;
    }

    /**
     * La fine dopo `$months` mesi dall'inizio, contati nel fuso della città:
     * il 31 gennaio più un mese è il 28 febbraio lì, non in UTC.
     */
    public static function endAfterMonths(CarbonImmutable $start, int $months, string $timezone): ?CarbonImmutable
    {
        if (! in_array($months, self::MONTHS, true)) {
            return null;
        }

        return $start->setTimezone($timezone)->addMonthsNoOverflow($months)->utc();
    }

    public static function listPrice(mixed $placement, mixed $months): ?SponsorshipPrice
    {
        $placement = SponsorshipPlacement::tryFrom(is_string($placement) ? $placement : '');
        $months = filter_var($months, FILTER_VALIDATE_INT);

        if ($placement === null || $months === false) {
            return null;
        }

        return SponsorshipPrice::query()->active()->where('placement', $placement)->where('months', $months)->first();
    }

    /** La frase sotto l'importo: il prezzo del listino, o che non c'è. */
    public static function priceHint(?SponsorshipPrice $price): string
    {
        if ($price !== null) {
            return __('sponsorships.wizard.help.price_from_list', ['price' => $price->formattedAmount(), 'months' => trans_choice('sponsorships.wizard.months', $price->months, ['count' => $price->months])]);
        }

        return __('sponsorships.wizard.help.price_none');
    }

    // ───────────────────────────── La stima ─────────────────────────────

    /**
     * Quanto può dare lo spazio nel periodo. Per «un evento» la stima si
     * ferma alla sua ultima data, come il motore.
     *
     * @return array{views: int, days: int, provisional: bool}|null
     */
    public function estimate(mixed $cityId, mixed $placement, ?CarbonImmutable $start, ?CarbonImmutable $end, string $scope, mixed $eventId): ?array
    {
        $placement = SponsorshipPlacement::tryFrom(is_string($placement) ? $placement : '');
        $city = is_numeric($cityId) ? City::query()->find((int) $cityId) : null;

        $lastCovered = $scope === self::SCOPE_EVENT && is_numeric($eventId)
            ? SponsorshipCapacity::lastCoveredFor((int) $eventId, $placement)
            : null;

        return $this->capacity->estimate($city, $placement, $start, $end, lastCovered: $lastCovered);
    }

    /**
     * Chi occupa già la collocazione nel periodo, nella città.
     *
     * @return list<array{key: string, name: string, kind: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, goal: int|null, events: int}>
     */
    public function occupants(mixed $cityId, mixed $placement, ?CarbonImmutable $start, ?CarbonImmutable $end): array
    {
        $placement = SponsorshipPlacement::tryFrom(is_string($placement) ? $placement : '');
        $city = is_numeric($cityId) ? City::query()->find((int) $cityId) : null;

        return $this->capacity->occupants($city, $placement, $start, $end);
    }

    /**
     * Il messaggio che ferma l'obiettivo, oppure `null`: le collocazioni solo
     * a periodo, e oltre l'80% della stima (`quota_max`).
     *
     * @param  array{views: int, days: int, provisional: bool}|null  $estimate
     */
    public static function goalProblem(mixed $placement, int $goal, ?array $estimate): ?string
    {
        $periodOnly = self::periodOnlyMessage($placement);

        if ($periodOnly !== null) {
            return $periodOnly;
        }

        if ($estimate !== null && SponsorshipCapacity::verdict($goal, $estimate['views']) === 'blocked') {
            return __('sponsorships.wizard.validation.goal_blocked', [
                'estimate' => number_format($estimate['views'], 0, ',', '.'),
                'max' => number_format((int) floor($estimate['views'] * SponsorshipCapacity::blockShare()), 0, ',', '.'),
            ]);
        }

        return null;
    }

    /** @param  array{views: int, days: int, provisional: bool}  $estimate */
    public static function estimateHeading(array $estimate): string
    {
        return __('sponsorships.wizard.estimate.heading', ['views' => number_format($estimate['views'], 0, ',', '.')]);
    }

    /**
     * La frase sotto la stima: i giorni di dati, se è provvisoria e, con un
     * obiettivo, se è nei limiti.
     *
     * @param  array{views: int, days: int, provisional: bool}  $estimate
     */
    public static function estimateDescription(array $estimate, ?int $goal): string
    {
        $parts = [$estimate['days'] > 0
            ? trans_choice('sponsorships.wizard.estimate.days', $estimate['days'], ['count' => $estimate['days']])
            : __('sponsorships.wizard.estimate.no_data')];

        if ($estimate['provisional']) {
            $parts[] = __('sponsorships.wizard.estimate.provisional');
        }

        if ($goal !== null) {
            $parts[] = match (SponsorshipCapacity::verdict($goal, $estimate['views'])) {
                'blocked' => __('sponsorships.wizard.estimate.blocked'),
                'warning' => __('sponsorships.wizard.estimate.warning', ['share' => number_format($estimate['views'] > 0 ? $goal / $estimate['views'] * 100 : 0, 0, ',', '.')]),
                default => __('sponsorships.wizard.estimate.ok'),
            };
        }

        return implode(' ', $parts);
    }

    /**
     * `info`, `success`, `warning` o `danger`: il colore del riquadro della stima.
     *
     * @param  array{views: int, days: int, provisional: bool}|null  $estimate
     */
    public static function estimateStatus(?array $estimate, ?int $goal, bool $views): string
    {
        if ($estimate === null || $goal === null || ! $views) {
            return 'info';
        }

        return match (SponsorshipCapacity::verdict($goal, $estimate['views'])) {
            'blocked' => 'danger',
            'warning' => 'warning',
            default => 'success',
        };
    }

    // ─────────────────────────── Le regole dei passi ───────────────────────────

    /**
     * Le regole dei passi da 1 a `$step` (4 = tutto il percorso), con i
     * messaggi del pannello: un passo non si supera finché i suoi campi non
     * vanno bene, e l'ultimo controlla tutto. Gli importi arrivano in euro
     * (`amount`), le date come istanti o come ore del fuso della città.
     *
     * Restituisce i dati pronti per `save()`: date in UTC, importo in centesimi.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $input, int $step = 4): array
    {
        $type = $input['sponsor_type'] ?? null;
        $scope = self::scopeOf($type, $input['scope'] ?? null);
        $views = ($input['product'] ?? self::PRODUCT_PERIOD) === self::PRODUCT_VIEWS;
        $complimentary = filter_var($input['complimentary'] ?? false, FILTER_VALIDATE_BOOL);
        $sponsor = is_string($type) ? self::sponsor($type, $input['venue_id'] ?? null, $input['organizer_id'] ?? null) : null;
        $cityId = self::cityIdFor($scope, $input['event_id'] ?? null, $sponsor);
        $timezone = self::timezone($cityId);
        $input['starts_at'] = self::instantIn($input['starts_at'] ?? null, $timezone)?->toIso8601String() ?? ($input['starts_at'] ?? null);
        $input['ends_at'] = self::instantIn($input['ends_at'] ?? null, $timezone)?->toIso8601String() ?? ($input['ends_at'] ?? null);
        $input['paid_at'] = self::instantIn($input['paid_at'] ?? null, $timezone)?->toIso8601String() ?? ($input['paid_at'] ?? null);

        $rules = [];

        if ($step >= 1) {
            $rules += [
                'sponsor_type' => ['required', Rule::in([self::SPONSOR_VENUE, self::SPONSOR_ORGANIZER, self::SPONSOR_EXTERNAL])],
                'venue_id' => [Rule::requiredIf($type === self::SPONSOR_VENUE), 'nullable', 'integer',
                    Rule::exists('venues', 'id')->where('status', VenueStatus::Approved->value)],
                'organizer_id' => [Rule::requiredIf($type === self::SPONSOR_ORGANIZER), 'nullable', 'integer',
                    Rule::exists('organizers', 'id')->where('is_active', true)],
                'advertiser_name' => ['required', 'string', 'max:255'],
                'advertiser_email' => ['nullable', 'string', 'email', 'max:255'],
                'advertiser_url' => ['nullable', 'string', 'url', 'max:255'],
            ];
        }

        if ($step >= 2) {
            $rules += [
                'scope' => [Rule::requiredIf($type !== self::SPONSOR_EXTERNAL), 'nullable', Rule::in([self::SCOPE_EVENT, self::SCOPE_ALL])],
                'event_id' => [Rule::requiredIf($scope === self::SCOPE_EVENT), 'nullable', 'integer',
                    function (string $attribute, mixed $value, \Closure $fail) use ($type, $input, $scope): void {
                        if ($scope !== self::SCOPE_EVENT || ! is_numeric($value)) {
                            return;
                        }
                        $query = self::eventsQuery($type, $input['venue_id'] ?? null, $input['organizer_id'] ?? null, $input['venue_filter'] ?? null);
                        if ($query === null || ! SponsorableEventChoices::sponsorable($query)->whereKey((int) $value)->exists()) {
                            $fail(__('sponsorships.wizard.validation.event_not_sponsorable'));
                        }
                    }],
            ];
        }

        if ($step >= 3) {
            $estimate = null;
            $rules += [
                'placement' => ['required', Rule::enum(SponsorshipPlacement::class)],
                'starts_at' => ['required', 'date'],
                'ends_at' => ['required', 'date', 'after:starts_at'],
                'product' => ['required', Rule::in([self::PRODUCT_PERIOD, self::PRODUCT_VIEWS]),
                    function (string $attribute, mixed $value, \Closure $fail) use ($input): void {
                        if ($value === self::PRODUCT_VIEWS && ($message = self::periodOnlyMessage($input['placement'] ?? null)) !== null) {
                            $fail($message);
                        }
                    }],
                'impressions_goal' => [Rule::requiredIf($views), 'nullable', 'integer', 'min:1', 'max:10000000',
                    function (string $attribute, mixed $value, \Closure $fail) use ($input, $views, $cityId, $scope, &$estimate): void {
                        if (! $views || ! is_numeric($value)) {
                            return;
                        }
                        $estimate = $this->estimate($cityId, $input['placement'] ?? null, self::instant($input['starts_at'] ?? null), self::instant($input['ends_at'] ?? null), $scope, $input['event_id'] ?? null);
                        if (($problem = self::goalProblem($input['placement'] ?? null, (int) $value, $estimate)) !== null) {
                            $fail($problem);
                        }
                    }],
                'pacing' => [Rule::requiredIf($views), 'nullable', Rule::enum(SponsorshipPacing::class)],
                'months' => ['nullable', 'integer', Rule::in(self::MONTHS)],
            ];
        }

        if ($step >= 4) {
            $rules += [
                'complimentary' => ['sometimes', 'boolean'],
                'amount' => [Rule::requiredIf(! $complimentary), 'nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000000'],
                'invoice_reference' => ['nullable', 'string', 'max:255'],
                'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
                'payment_method' => ['nullable', 'string', 'max:100'],
                'payment_reference' => ['nullable', 'string', 'max:255'],
                'notes' => [Rule::requiredIf($complimentary), 'nullable', 'string', 'max:5000'],
                'status' => ['required', Rule::in([SponsorshipStatus::Active->value, SponsorshipStatus::Draft->value])],
                'priority' => ['required', 'integer', 'min:0', 'max:1000'],
                'weight' => ['required', 'integer', 'min:1', 'max:100'],
            ];
        }

        $validated = Validator::make($input, $rules, [
            'ends_at.after' => __('sponsorships.admin.validation.ends_after_starts'),
        ], self::attributes())->validate();

        return [
            ...$validated,
            'sponsor_type' => $type,
            'scope' => $scope,
            'city_id' => $cityId,
            'timezone' => $timezone,
            'complimentary' => $complimentary,
            'starts_at' => self::instant($validated['starts_at'] ?? null),
            'ends_at' => self::instant($validated['ends_at'] ?? null),
            'paid_at' => self::instant($validated['paid_at'] ?? null),
            'amount_cents' => isset($validated['amount']) && is_numeric($validated['amount']) ? (int) round((float) $validated['amount'] * 100) : null,
        ];
    }

    /**
     * I nomi dei campi nei messaggi, come le etichette del pannello.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'sponsor_type' => __('sponsorships.wizard.fields.sponsor_type'),
            'venue_id' => __('sponsorships.wizard.fields.venue'),
            'organizer_id' => __('sponsorships.wizard.fields.organizer'),
            'advertiser_name' => __('sponsorships.admin.fields.advertiser_name'),
            'advertiser_email' => __('sponsorships.admin.fields.advertiser_email'),
            'advertiser_url' => __('sponsorships.admin.fields.advertiser_url'),
            'scope' => __('sponsorships.wizard.fields.scope'),
            'event_id' => __('sponsorships.picker.label'),
            'placement' => __('sponsorships.admin.fields.placement'),
            'starts_at' => __('sponsorships.admin.fields.starts_at'),
            'ends_at' => __('sponsorships.admin.fields.ends_at'),
            'product' => __('sponsorships.wizard.fields.product'),
            'impressions_goal' => __('sponsorships.wizard.fields.goal'),
            'pacing' => __('sponsorships.wizard.fields.pacing'),
            'amount' => __('sponsorships.admin.fields.amount'),
            'invoice_reference' => __('sponsorships.admin.fields.invoice_reference'),
            'paid_at' => __('promotions.paid'),
            'payment_method' => __('promotions.method'),
            'payment_reference' => __('promotions.reference'),
            'notes' => __('sponsorships.admin.fields.notes'),
            'status' => __('sponsorships.wizard.fields.status'),
            'priority' => __('sponsorships.admin.fields.priority'),
            'weight' => __('sponsorships.admin.fields.weight'),
        ];
    }

    /** Un istante scritto in ISO 8601 (o nell'ora dell'applicazione), in UTC. */
    public static function instant(mixed $state): ?CarbonImmutable
    {
        if ($state instanceof CarbonImmutable) {
            return $state->utc();
        }

        if (! is_string($state) || blank($state)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($state, config()->string('app.timezone'))->utc();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * Un'ora scritta dall'app: con il suo scarto se c'è (`2026-11-20T21:30:00+01:00`),
     * altrimenti nell'ora della città (`2026-11-20 21:30`).
     */
    public static function instantIn(mixed $state, string $timezone): ?CarbonImmutable
    {
        if (! is_string($state) || blank($state)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($state, $timezone)->utc();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private static function intOr(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
