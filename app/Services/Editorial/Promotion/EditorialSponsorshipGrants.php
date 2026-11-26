<?php

declare(strict_types=1);

namespace App\Services\Editorial\Promotion;

use App\Enums\PromotionMode;
use App\Enums\SponsorshipPacing;
use App\Enums\SponsorshipPlacement;
use App\Models\SponsorshipGrant;
use App\Models\User;
use App\Services\Sponsorship\Editorial\CampaignBuilder;
use App\Services\Sponsorship\Editorial\ProgressDetail;
use App\Services\Sponsorship\Editorial\SponsorshipProgress;
use App\Services\Sponsorship\GrantCampaigns;
use App\Services\Sponsorship\GrantPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Le abilitazioni («Abilitazioni e pagamenti», R-SP-11…12) nella redazione
 * dell'app, come `SponsorshipGrantResource`: l'elenco con i filtri per locale
 * e organizzatore, l'avanzamento e i numeri, il nuovo e la modifica con le
 * regole del modulo del pannello. Nessuna eliminazione
 * (`SponsorshipGrantPolicy::delete` è sempre no).
 *
 * Al salvataggio succede quello che fanno le pagine del pannello: chi la crea
 * (`created_by`), il pagamento svuotato per le gratuite (`GrantPayment`) e la
 * sincronizzazione delle righe degli eventi (`GrantCampaigns::sync`). Il
 * registro attività lo scrive il modello (`LogsActivity`).
 *
 * Le date del modulo sono nel fuso di Padova, come nel pannello.
 */
final class EditorialSponsorshipGrants
{
    /** Il fuso del modulo delle abilitazioni nel pannello. */
    public const TIMEZONE = 'Europe/Rome';

    /** @var list<string> */
    public const FILTERS = ['venue', 'organizer'];

    private ?SponsorshipProgress $progress = null;

    public function __construct(
        private readonly GrantPayment $payment,
        private readonly GrantCampaigns $campaigns,
    ) {}

    /**
     * @param  array<string, string>  $filters
     * @return LengthAwarePaginator<int, SponsorshipGrant>
     */
    public function paginate(string $search, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $search = trim($search);

        return SponsorshipGrant::query()
            ->with(['venue', 'organizer'])
            ->withSum('sponsorships', 'impressions')
            ->withSum('sponsorships', 'clicks')
            ->when(is_numeric($filters['venue'] ?? null), fn (Builder $q): Builder => $q->where('venue_id', (int) $filters['venue']))
            ->when(is_numeric($filters['organizer'] ?? null), fn (Builder $q): Builder => $q->where('organizer_id', (int) $filters['organizer']))
            ->when($search !== '', fn (Builder $q): Builder => $q->where(fn (Builder $match): Builder => $match
                ->whereHas('venue', fn (Builder $venue): Builder => $venue->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
                ->orWhereHas('organizer', fn (Builder $organizer): Builder => $organizer->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))))
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->paginate($perPage, page: $page);
    }

    /** @return array<string, mixed> */
    public function row(SponsorshipGrant $grant, User $user): array
    {
        $gate = Gate::forUser($user);

        return [
            'id' => (int) $grant->getKey(),
            'sponsor' => $grant->sponsorName(),
            'sponsor_kind' => $grant->venue_id !== null ? __('promotions.venue') : __('promotions.organizer'),
            'status_label' => $grant->statusLabel(),
            'mode' => ['value' => $grant->mode->value, 'label' => $grant->mode->label()],
            'placement' => PromotionPayload::placement($grant->placement),
            'enabled' => (bool) $grant->enabled,
            'complimentary' => (bool) $grant->complimentary,
            'timezone' => self::TIMEZONE,
            'starts_at' => PromotionPayload::instant($grant->starts_at, self::TIMEZONE),
            'ends_at' => PromotionPayload::instant($grant->ends_at, self::TIMEZONE),
            'paid_at' => PromotionPayload::instant($grant->paid_at, self::TIMEZONE),
            'amount_label' => $grant->amount_cents === null ? null : (string) Number::currency($grant->amount_cents / 100, in: 'EUR', locale: 'it'),
            'progress' => PromotionPayload::progress($this->progress()->forGrant($grant)),
            'impressions' => (int) $grant->getAttribute('sponsorships_sum_impressions'),
            'clicks' => (int) $grant->getAttribute('sponsorships_sum_clicks'),
            'abilities' => [
                'update' => $gate->allows('update', $grant),
                'destroy' => $gate->allows('delete', $grant),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function detail(SponsorshipGrant $grant, User $user): array
    {
        $grant->loadMissing(['venue', 'organizer'])->loadSum('sponsorships', 'impressions')->loadSum('sponsorships', 'clicks');

        return [
            ...$this->row($grant, $user),
            'form' => [
                'venue_id' => $grant->venue_id === null ? null : (int) $grant->venue_id,
                'venue_label' => $grant->venue?->name,
                'organizer_id' => $grant->organizer_id === null ? null : (int) $grant->organizer_id,
                'organizer_label' => $grant->organizer?->name,
                'mode' => $grant->mode->value,
                'placement' => $grant->placement->value,
                'enabled' => (bool) $grant->enabled,
                'impressions_goal' => $grant->impressions_goal,
                'pacing' => ($grant->pacing ?? SponsorshipPacing::Even)->value,
                'priority' => (int) $grant->priority,
                'weight' => (int) $grant->weight,
                'starts_at' => PromotionPayload::instant($grant->starts_at, self::TIMEZONE),
                'ends_at' => PromotionPayload::instant($grant->ends_at, self::TIMEZONE),
                'complimentary' => (bool) $grant->complimentary,
                'amount' => PromotionPayload::euros($grant->amount_cents),
                'paid_at' => PromotionPayload::instant($grant->paid_at, self::TIMEZONE),
                'payment_method' => $grant->payment_method,
                'payment_reference' => $grant->payment_reference,
                'notes' => $grant->notes,
            ],
            'period_only' => CampaignBuilder::periodOnlyMessage($grant->placement),
            'progress_detail' => PromotionPayload::progressDetail(app(ProgressDetail::class)->for($grant)),
            'choices' => self::choices(),
        ];
    }

    /**
     * La nuova abilitazione: le regole del modulo, poi `created_by`, il
     * pagamento e la sincronizzazione delle righe (`CreateGrant`).
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, User $user): SponsorshipGrant
    {
        $data = $this->validate($input, null);
        $data['created_by'] = $user->getKey();

        $grant = DB::transaction(fn (): SponsorshipGrant => SponsorshipGrant::query()->create($this->payment->normalize($data)));
        $this->campaigns->sync($grant);

        return $grant->refresh();
    }

    /**
     * La modifica: committente, modalità e collocazione non si cambiano
     * (spenti nel pannello), il resto sì (`EditGrant`).
     *
     * @param  array<string, mixed>  $input
     */
    public function update(SponsorshipGrant $grant, array $input): SponsorshipGrant
    {
        unset($input['venue_id'], $input['organizer_id'], $input['mode'], $input['placement']);
        $data = $this->validate($input, $grant);

        DB::transaction(fn (): bool => $grant->fill($this->payment->normalize($data))->save());
        $this->campaigns->sync($grant);

        return $grant->refresh();
    }

    /**
     * Le regole dei campi del modulo del pannello. Il nuovo le chiede tutte;
     * la modifica convalida le chiavi ricevute sopra i valori di adesso.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $input, ?SponsorshipGrant $grant): array
    {
        foreach (['starts_at', 'ends_at', 'paid_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = CampaignBuilder::instantIn($input[$field], self::TIMEZONE)?->toIso8601String() ?? $input[$field];
            }
        }
        $current = $grant === null ? [] : [
            'placement' => $grant->placement->value,
            'starts_at' => $grant->starts_at->toIso8601String(),
            'ends_at' => $grant->ends_at->toIso8601String(),
            'complimentary' => (bool) $grant->complimentary,
            'pacing' => ($grant->pacing ?? SponsorshipPacing::Even)->value,
            'priority' => (int) $grant->priority,
            'weight' => (int) $grant->weight,
            'amount' => PromotionPayload::euros($grant->amount_cents),
            'notes' => $grant->notes,
        ];
        $state = [...$current, ...$input];
        $complimentary = filter_var($state['complimentary'] ?? false, FILTER_VALIDATE_BOOL);
        $state['complimentary'] = $complimentary;
        $creating = $grant === null;

        $rules = [
            'enabled' => ['sometimes', 'boolean'],
            'impressions_goal' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000',
                Rule::prohibitedIf(in_array($state['placement'] ?? null, SponsorshipPlacement::periodOnlyValues(), true))],
            'pacing' => ['required', Rule::enum(SponsorshipPacing::class)],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
            'weight' => ['required', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'complimentary' => ['boolean'],
            'amount' => [Rule::requiredIf(! $complimentary), 'nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000000'],
            'paid_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:100'],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => [Rule::requiredIf($complimentary), 'nullable', 'string', 'max:5000'],
        ];
        if ($creating) {
            $rules += [
                'venue_id' => ['nullable', 'integer', 'required_without:organizer_id', 'prohibits:organizer_id', Rule::exists('venues', 'id')],
                'organizer_id' => ['nullable', 'integer', 'required_without:venue_id', Rule::exists('organizers', 'id')],
                'mode' => ['required', Rule::enum(PromotionMode::class)],
                'placement' => ['required', Rule::enum(SponsorshipPlacement::class)],
            ];
            $state += ['mode' => PromotionMode::Selected->value, 'placement' => SponsorshipPlacement::ListTop->value, 'enabled' => true,
                'pacing' => SponsorshipPacing::Even->value, 'priority' => 0, 'weight' => 1];
        }

        $validated = Validator::make($state, $rules, [
            'venue_id.required_without' => __('promotions.sponsor_required'),
            'organizer_id.required_without' => __('promotions.sponsor_required'),
            'venue_id.prohibits' => __('promotions.sponsor_exclusive'),
            'impressions_goal.prohibited' => __('sponsorships.wizard.help.period_only'),
        ], self::attributes())->validate();

        $data = [];
        $fields = $creating
            ? ['venue_id', 'organizer_id', 'mode', 'placement', 'enabled', 'impressions_goal', 'pacing', 'priority', 'weight', 'complimentary', 'payment_method', 'payment_reference', 'notes']
            : array_intersect(['enabled', 'impressions_goal', 'pacing', 'priority', 'weight', 'complimentary', 'payment_method', 'payment_reference', 'notes'], array_keys($input));
        foreach ($fields as $field) {
            $data[$field] = $validated[$field] ?? ($field === 'enabled' ? true : null);
        }
        foreach (['starts_at', 'ends_at', 'paid_at'] as $field) {
            if ($creating || array_key_exists($field, $input)) {
                $data[$field] = CampaignBuilder::instant($validated[$field] ?? null);
            }
        }
        if ($creating || array_key_exists('amount', $input) || array_key_exists('complimentary', $input)) {
            $amount = $complimentary ? null : ($validated['amount'] ?? null);
            $data['amount_cents'] = $amount === null ? null : (int) round((float) $amount * 100);
        }
        if (array_key_exists('complimentary', $data) || $creating) {
            $data['complimentary'] = $complimentary;
        }

        return $data;
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    public static function choices(): array
    {
        return [
            'modes' => PromotionPayload::choices(PromotionMode::options()),
            'placements' => PromotionPayload::choices(SponsorshipPlacement::options()),
            'pacings' => PromotionPayload::choices(CampaignBuilder::pacingOptions()),
            'months' => PromotionPayload::choices(array_combine(array_map('strval', CampaignBuilder::MONTHS), array_map('strval', CampaignBuilder::MONTHS))),
        ];
    }

    /** La fine dopo `$months` mesi, nel fuso del modulo (`updateEndDate` del pannello). */
    public static function endAfterMonths(?string $start, int $months): ?string
    {
        $instant = CampaignBuilder::instantIn($start, self::TIMEZONE);
        if ($instant === null) {
            return null;
        }

        $end = CampaignBuilder::endAfterMonths($instant, $months, self::TIMEZONE);

        return $end === null ? null : PromotionPayload::instant(CarbonImmutable::instance($end), self::TIMEZONE);
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'venue_id' => __('promotions.venue'),
            'organizer_id' => __('promotions.organizer'),
            'mode' => __('promotions.mode_label'),
            'placement' => __('promotions.placement'),
            'enabled' => __('promotions.enabled'),
            'impressions_goal' => __('sponsorships.admin.fields.impressions_goal'),
            'pacing' => __('sponsorships.wizard.fields.pacing'),
            'priority' => __('sponsorships.admin.fields.priority'),
            'weight' => __('sponsorships.admin.fields.weight'),
            'starts_at' => __('promotions.starts'),
            'ends_at' => __('promotions.ends'),
            'complimentary' => __('promotions.complimentary'),
            'amount' => __('promotions.amount'),
            'paid_at' => __('promotions.paid'),
            'payment_method' => __('promotions.method'),
            'payment_reference' => __('promotions.reference'),
            'notes' => __('promotions.notes'),
        ];
    }

    private function progress(): SponsorshipProgress
    {
        return $this->progress ??= app(SponsorshipProgress::class);
    }
}
