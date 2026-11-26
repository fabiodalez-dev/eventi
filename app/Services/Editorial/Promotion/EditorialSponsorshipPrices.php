<?php

declare(strict_types=1);

namespace App\Services\Editorial\Promotion;

use App\Enums\SponsorshipPlacement;
use App\Models\SponsorshipPrice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Il listino (R-SP-13) nella redazione dell'app, come
 * `SponsorshipPriceResource`: una collocazione per una durata a un prezzo,
 * una sola voce per coppia collocazione-durata. L'eliminazione è quella del
 * pannello (la voce sparisce; gli ordini già fatti ne hanno copiato i valori).
 */
final class EditorialSponsorshipPrices
{
    /** @var list<string> */
    public const SORTS = ['placement', 'months', 'amount_cents'];

    /**
     * @param  array<string, string>  $filters
     * @return LengthAwarePaginator<int, SponsorshipPrice>
     */
    public function paginate(array $filters, string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (! in_array($column, self::SORTS, true)) {
            [$column, $direction] = ['placement', 'asc'];
        }

        return SponsorshipPrice::query()
            ->when(in_array($filters['is_active'] ?? null, ['0', '1'], true), fn (Builder $q): Builder => $q->where('is_active', $filters['is_active'] === '1'))
            ->orderBy($column, $direction)
            ->orderBy('months')
            ->orderBy('id')
            ->paginate($perPage, page: $page);
    }

    /** @return array<string, mixed> */
    public function row(SponsorshipPrice $price, User $user): array
    {
        $gate = Gate::forUser($user);

        return [
            'id' => (int) $price->getKey(),
            'placement' => PromotionPayload::placement($price->placement),
            'months' => (int) $price->months,
            'amount' => PromotionPayload::euros((int) $price->amount_cents),
            'amount_label' => $price->formattedAmount(),
            'is_active' => (bool) $price->is_active,
            'abilities' => [
                'update' => $gate->allows('update', $price),
                'destroy' => $gate->allows('delete', $price),
            ],
        ];
    }

    /** @param  array<string, mixed>  $input */
    public function create(array $input): SponsorshipPrice
    {
        $data = $this->validate($input, null);

        return SponsorshipPrice::query()->create($data);
    }

    /** @param  array<string, mixed>  $input */
    public function update(SponsorshipPrice $price, array $input): SponsorshipPrice
    {
        $price->fill($this->validate($input, $price))->save();

        return $price->refresh();
    }

    /**
     * Le regole del modulo: collocazione, durata fra quelle del listino e mai
     * due volte per la stessa collocazione, importo in euro con due decimali.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $input, ?SponsorshipPrice $price): array
    {
        $state = [
            'placement' => $price?->placement->value,
            'months' => $price?->months,
            'amount' => $price === null ? null : PromotionPayload::euros((int) $price->amount_cents),
            'is_active' => $price === null ? true : (bool) $price->is_active,
            ...$input,
        ];

        $validated = Validator::make($state, [
            'placement' => ['required', Rule::enum(SponsorshipPlacement::class)],
            'months' => ['required', 'integer', Rule::in(SponsorshipPrice::MONTHS),
                Rule::unique('sponsorship_prices', 'months')->ignore($price?->getKey())->where('placement', (string) ($state['placement'] ?? ''))],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000000'],
            'is_active' => ['boolean'],
        ], [
            'months.unique' => __('promotions.prices.duplicate'),
        ], [
            'placement' => __('promotions.placement'),
            'months' => __('promotions.prices.months'),
            'amount' => __('promotions.prices.amount'),
            'is_active' => __('promotions.prices.active'),
        ])->validate();

        return [
            'placement' => $validated['placement'],
            'months' => (int) $validated['months'],
            'amount_cents' => (int) round((float) $validated['amount'] * 100),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    public static function choices(): array
    {
        return [
            'placements' => PromotionPayload::choices(SponsorshipPlacement::options()),
            'months' => PromotionPayload::choices(array_combine(array_map('strval', SponsorshipPrice::MONTHS), array_map('strval', SponsorshipPrice::MONTHS))),
        ];
    }
}
