<?php

declare(strict_types=1);

namespace App\Services\Editorial\Promotion;

use App\Enums\EventStatus;
use App\Enums\SponsorshipPacing;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Models\Event;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\Sponsorship\Delivery\PacingState;
use App\Services\Sponsorship\Editorial\CampaignBuilder;
use App\Services\Sponsorship\Editorial\ProgressDetail;
use App\Services\Sponsorship\Editorial\SponsorshipProgress;
use App\Support\Sponsorship\SponsorableEventChoices;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Le campagne sponsorizzate nella redazione dell'app (R-SP-01…06), come
 * `SponsorshipResource` del pannello: l'elenco con i filtri (collocazione,
 * stato, avanzamento, «solo quelle in corso») e la ricerca (titolo
 * dell'evento, committente), la scheda con l'avanzamento, la modifica con
 * le quattro schede (campagna, periodo e limiti, visualizzazioni, cliente e
 * ricavo) e le loro regole.
 *
 * Una riga di un'abilitazione tiene evento, priorità, peso, obiettivo e
 * distribuzione dell'abilitazione: nel pannello sono campi spenti e non si
 * salvano, qui si ignorano allo stesso modo.
 */
final class EditorialSponsorships
{
    /** @var list<string> */
    public const FILTERS = ['placement', 'status', 'pacing_state', 'running'];

    /** @var list<string> */
    public const SORTS = ['starts_at', 'ends_at', 'impressions', 'clicks'];

    /** L'importo massimo in euro, lo stesso della procedura guidata e del listino. */
    public const AMOUNT_MAX = 1_000_000;

    /** Il tetto massimo di visualizzazioni o clic: ben dentro le colonne, mai un numero che il cast a intero storpia. */
    public const CAP_MAX = 1_000_000_000;

    /** Una sola istanza per elenco: previsioni e unità già calcolate restano in memoria fra le righe. */
    private ?SponsorshipProgress $progress = null;

    /**
     * La query dell'elenco con ricerca, filtri e ordine.
     *
     * @param  array<string, string>  $filters
     * @return Builder<Sponsorship>
     */
    public function query(string $search, array $filters, string $sort): Builder
    {
        $query = Sponsorship::query()->with(['event.media', 'event.city', 'event.venue:id,name', 'city', 'grant.venue', 'grant.organizer']);
        $search = trim($search);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $match): Builder => $match
                ->whereHas('event', fn (Builder $event): Builder => $event->where('title', 'like', $like))
                ->orWhere('advertiser_name', 'like', $like));
        }

        if (($placement = SponsorshipPlacement::tryFrom($filters['placement'] ?? '')) !== null) {
            $query->where('placement', $placement);
        }

        if (($status = SponsorshipStatus::tryFrom($filters['status'] ?? '')) !== null) {
            $query->where('status', $status);
        }

        if (array_key_exists($filters['pacing_state'] ?? '', self::pacingStateOptions())) {
            $query->whereKey($this->progress()->sponsorshipIdsInState((string) $filters['pacing_state']));
        }

        if (($filters['running'] ?? '') === '1') {
            $query->whereKey(Sponsorship::query()->visible()->pluck('id'));
        }

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (! in_array($column, self::SORTS, true)) {
            [$column, $direction] = ['ends_at', 'desc'];
        }

        return $query->orderBy($column, $direction)->orderByDesc('id');
    }

    /**
     * @param  array<string, string>  $filters
     * @return LengthAwarePaginator<int, Sponsorship>
     */
    public function paginate(string $search, array $filters, string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->query($search, $filters, $sort)->paginate($perPage, page: $page);
    }

    /** @return array<string, mixed> */
    public function row(Sponsorship $campaign, User $user): array
    {
        $gate = Gate::forUser($user);
        $tz = self::timezoneOf($campaign);
        $phase = $campaign->phase();
        $rate = $campaign->clickRate();
        $active = $campaign->status === SponsorshipStatus::Active;

        return [
            'id' => (int) $campaign->getKey(),
            'event' => PromotionPayload::event($campaign->event, $tz),
            'event_gone' => $campaign->event === null ? __('sponsorships.analytics.event_gone') : null,
            'placement' => PromotionPayload::placement($campaign->placement),
            'status' => ['value' => $campaign->status->value, 'label' => $campaign->status->label()],
            'phase' => ['value' => $phase->value, 'label' => $phase->label(), 'color' => $phase->color()],
            'progress' => PromotionPayload::progress($this->progress()->forSponsorship($campaign)),
            'timezone' => $tz,
            'starts_at' => PromotionPayload::instant($campaign->starts_at, $tz),
            'ends_at' => PromotionPayload::instant($campaign->ends_at, $tz),
            'advertiser_name' => (string) $campaign->advertiser_name,
            'impressions' => (int) $campaign->impressions,
            'clicks' => (int) $campaign->clicks,
            'click_rate' => $rate === null ? '—' : number_format($rate * 100, 1).'%',
            'grant_id' => $campaign->sponsorship_grant_id === null ? null : (int) $campaign->sponsorship_grant_id,
            'toggle_label' => $active ? __('sponsorships.admin.actions.pause') : __('sponsorships.admin.actions.activate'),
            'abilities' => [
                'update' => $gate->allows('update', $campaign),
                'toggle' => $gate->allows('update', $campaign),
                'destroy' => $gate->allows('delete', $campaign),
            ],
        ];
    }

    /**
     * La scheda di una campagna: la riga, i valori del modulo, l'avanzamento
     * e le scelte delle tendine.
     *
     * @return array<string, mixed>
     */
    public function detail(Sponsorship $campaign, User $user): array
    {
        $campaign->loadMissing(['event.media', 'event.city', 'event.venue:id,name', 'city', 'grant.venue', 'grant.organizer']);
        $tz = self::timezoneOf($campaign);
        $onGrant = $campaign->sponsorship_grant_id !== null;

        return [
            ...$this->row($campaign, $user),
            'form' => [
                'event_id' => (int) $campaign->event_id,
                'placement' => $campaign->placement->value,
                'status' => $campaign->status->value,
                'starts_at' => PromotionPayload::instant($campaign->starts_at, $tz),
                'ends_at' => PromotionPayload::instant($campaign->ends_at, $tz),
                'priority' => (int) $campaign->priority,
                'weight' => (int) $campaign->weight,
                'impressions_cap' => $campaign->impressions_cap,
                'clicks_cap' => $campaign->clicks_cap,
                'impressions_goal' => $campaign->impressions_goal,
                'pacing' => ($campaign->pacing ?? SponsorshipPacing::Even)->value,
                'advertiser_name' => (string) $campaign->advertiser_name,
                'advertiser_email' => $campaign->advertiser_email,
                'advertiser_url' => $campaign->advertiser_url,
                'amount' => PromotionPayload::euros($campaign->amount_cents),
                'currency' => (string) ($campaign->currency ?: 'EUR'),
                'invoice_reference' => $campaign->invoice_reference,
                'notes' => $campaign->notes,
            ],
            'on_grant' => $onGrant,
            'grant_help' => $onGrant ? __('sponsorships.admin.help.delivery_on_grant') : null,
            'event_warning' => $campaign->event !== null && $campaign->event->status !== EventStatus::Published ? __('sponsorships.admin.help.event_not_published') : null,
            'period_only' => CampaignBuilder::periodOnlyMessage($campaign->placement),
            'amount_label' => $campaign->formattedAmount(),
            'progress_detail' => PromotionPayload::progressDetail(app(ProgressDetail::class)->for($campaign)),
            'choices' => self::choices(),
        ];
    }

    /**
     * Salva le chiavi inviate con le regole del modulo del pannello. Se
     * l'evento cambia, la città lo segue (`EditSponsorship`).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(Sponsorship $campaign, array $input): Sponsorship
    {
        $onGrant = $campaign->sponsorship_grant_id !== null;
        if ($onGrant) {
            /* Campi spenti nel pannello: non si salvano. */
            unset($input['event_id'], $input['priority'], $input['weight'], $input['impressions_goal'], $input['pacing']);
        }

        $cityId = array_key_exists('event_id', $input) && is_numeric($input['event_id'])
            ? Event::query()->whereKey((int) $input['event_id'])->value('city_id')
            : $campaign->city_id;
        $tz = CampaignBuilder::timezone($cityId);
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = CampaignBuilder::instantIn($input[$field], $tz)?->toIso8601String() ?? $input[$field];
            }
        }
        $placement = $input['placement'] ?? $campaign->placement->value;
        $keep = (int) $campaign->event_id;

        /*
         * Le regole che legano due campi (la fine dopo l'inizio, l'obiettivo
         * vietato nelle collocazioni «solo a periodo») si controllano sui
         * valori uniti a quelli salvati: il pannello manda sempre il modulo
         * intero, un `PATCH` con una chiave sola no. Senza, un inizio dopo
         * la fine o un obiettivo vecchio rimasto su una collocazione a
         * periodo passerebbero. Chi passa a una collocazione a periodo manda
         * `impressions_goal: null`, come chi svuota il campo nel pannello.
         */
        $state = [
            ...$input,
            'placement' => $placement,
            'starts_at' => $input['starts_at'] ?? $campaign->starts_at->toIso8601String(),
            'ends_at' => $input['ends_at'] ?? $campaign->ends_at->toIso8601String(),
        ];
        if (! $onGrant && ! array_key_exists('impressions_goal', $input)) {
            $state['impressions_goal'] = $campaign->impressions_goal;
        }

        $validated = Validator::make($state, [
            'event_id' => ['sometimes', 'required', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($keep): void {
                if (! is_numeric($value) || ! SponsorableEventChoices::sponsorable(Event::query(), $keep)->whereKey((int) $value)->exists()) {
                    $fail(__('sponsorships.wizard.validation.event_not_sponsorable'));
                }
            }],
            'placement' => ['required', Rule::enum(SponsorshipPlacement::class)],
            'status' => ['sometimes', 'required', Rule::enum(SponsorshipStatus::class)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'priority' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'weight' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:100'],
            'impressions_cap' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::CAP_MAX],
            'clicks_cap' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::CAP_MAX],
            'impressions_goal' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000', Rule::prohibitedIf(in_array($placement, SponsorshipPlacement::periodOnlyValues(), true))],
            'pacing' => ['sometimes', 'required', Rule::enum(SponsorshipPacing::class)],
            'advertiser_name' => ['sometimes', 'required', 'string', 'max:255'],
            'advertiser_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'advertiser_url' => ['sometimes', 'nullable', 'string', 'url', 'max:255'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::AMOUNT_MAX],
            'currency' => ['sometimes', 'nullable', 'string', 'max:3'],
            'invoice_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ], [
            'ends_at.after' => __('sponsorships.admin.validation.ends_after_starts'),
            'impressions_goal.prohibited' => __('sponsorships.wizard.help.period_only'),
        ], self::attributes())->validate();

        $changes = [];
        foreach (['placement', 'status', 'priority', 'weight', 'impressions_cap', 'clicks_cap', 'impressions_goal', 'pacing', 'advertiser_name', 'advertiser_email', 'advertiser_url', 'invoice_reference', 'notes'] as $field) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = $validated[$field] ?? null;
            }
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $changes[$field] = CampaignBuilder::instant($validated[$field]);
            }
        }
        if (array_key_exists('event_id', $input)) {
            $changes['event_id'] = (int) $validated['event_id'];
            $changes['city_id'] = $cityId;
        }
        if (array_key_exists('amount', $input)) {
            $amount = $validated['amount'] ?? null;
            $changes['amount_cents'] = $amount === null || $amount === '' ? null : (int) round((float) $amount * 100);
        }
        if (array_key_exists('currency', $input)) {
            $changes['currency'] = $validated['currency'] ?? null;
        }
        foreach (['priority', 'weight', 'impressions_cap', 'clicks_cap', 'impressions_goal'] as $number) {
            if (array_key_exists($number, $changes) && $changes[$number] !== null) {
                $changes[$number] = (int) $changes[$number];
            }
        }

        $campaign->fill($changes)->save();

        return $campaign->refresh();
    }

    /**
     * Le scelte dei filtri e del modulo.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function choices(): array
    {
        return [
            'placements' => PromotionPayload::choices(SponsorshipPlacement::options()),
            'statuses' => PromotionPayload::choices(SponsorshipStatus::options()),
            'pacings' => PromotionPayload::choices(CampaignBuilder::pacingOptions()),
            'pacing_states' => PromotionPayload::choices(self::pacingStateOptions()),
        ];
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

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'event_id' => __('sponsorships.admin.fields.event'),
            'placement' => __('sponsorships.admin.fields.placement'),
            'status' => __('sponsorships.admin.fields.status'),
            'starts_at' => __('sponsorships.admin.fields.starts_at'),
            'ends_at' => __('sponsorships.admin.fields.ends_at'),
            'priority' => __('sponsorships.admin.fields.priority'),
            'weight' => __('sponsorships.admin.fields.weight'),
            'impressions_cap' => __('sponsorships.admin.fields.impressions_cap'),
            'clicks_cap' => __('sponsorships.admin.fields.clicks_cap'),
            'impressions_goal' => __('sponsorships.admin.fields.impressions_goal'),
            'pacing' => __('sponsorships.wizard.fields.pacing'),
            'advertiser_name' => __('sponsorships.admin.fields.advertiser_name'),
            'advertiser_email' => __('sponsorships.admin.fields.advertiser_email'),
            'advertiser_url' => __('sponsorships.admin.fields.advertiser_url'),
            'amount' => __('sponsorships.admin.fields.amount'),
            'currency' => __('sponsorships.admin.fields.currency'),
            'invoice_reference' => __('sponsorships.admin.fields.invoice_reference'),
            'notes' => __('sponsorships.admin.fields.notes'),
        ];
    }

    /**
     * Le intestazioni del foglio Excel, come le colonne dell'elenco.
     *
     * @return list<string>
     */
    public static function exportHeadings(): array
    {
        return [
            __('sponsorships.admin.fields.event'),
            __('sponsorships.admin.fields.placement'),
            __('sponsorships.admin.fields.status'),
            __('sponsorships.admin.fields.phase'),
            __('sponsorships.progress.column'),
            __('sponsorships.admin.fields.starts_at'),
            __('sponsorships.admin.fields.ends_at'),
            __('sponsorships.admin.fields.advertiser_name'),
            __('sponsorships.admin.fields.impressions'),
            __('sponsorships.admin.fields.clicks'),
            __('sponsorships.admin.fields.click_rate'),
        ];
    }

    /**
     * Le righe del foglio con i criteri dell'elenco, a blocchi.
     *
     * @param  array<string, string>  $filters
     * @return iterable<list<string|int>>
     */
    public function exportRows(string $search, array $filters, string $sort): iterable
    {
        foreach ($this->query($search, $filters, $sort)->lazy(200) as $campaign) {
            $tz = self::timezoneOf($campaign);
            $rate = $campaign->clickRate();

            yield [
                (string) ($campaign->event->title ?? __('sponsorships.analytics.event_gone')),
                $campaign->placement->label(),
                $campaign->status->label(),
                $campaign->phase()->label(),
                $this->progress()->forSponsorship($campaign)?->stateLabel() ?? '—',
                $campaign->starts_at->setTimezone($tz)->format('d/m/Y H:i'),
                $campaign->ends_at->setTimezone($tz)->format('d/m/Y H:i'),
                (string) $campaign->advertiser_name,
                (int) $campaign->impressions,
                (int) $campaign->clicks,
                $rate === null ? '—' : number_format($rate * 100, 1).'%',
            ];
        }
    }

    /** Il fuso della città della campagna, come la colonna del pannello. */
    public static function timezoneOf(Sponsorship $campaign): string
    {
        return $campaign->city?->timezone ?: config()->string('app.timezone');
    }

    private function progress(): SponsorshipProgress
    {
        return $this->progress ??= app(SponsorshipProgress::class);
    }
}
