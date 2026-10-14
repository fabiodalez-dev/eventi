<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SponsorshipPlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

/**
 * Una voce del listino: una posizione per un numero di mesi a un prezzo.
 *
 * Il listino lo scrive la redazione dal pannello, e un ordine ne copia i
 * valori al momento dell'acquisto: cambiare una riga qui non tocca gli ordini
 * gia' fatti.
 */
class SponsorshipPrice extends Model
{
    /** @var list<int> */
    public const MONTHS = [1, 2, 3, 6, 12];

    protected $guarded = ['id'];

    /** @param Builder<SponsorshipPrice> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function label(): string
    {
        return __('promotions.prices.label', [
            'placement' => $this->placement->label(),
            'months' => $this->months,
            'amount' => $this->formattedAmount(),
        ]);
    }

    public function formattedAmount(): string
    {
        return (string) Number::currency($this->amount_cents / 100, in: $this->currency ?: 'EUR', locale: app()->getLocale());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'placement' => SponsorshipPlacement::class,
            'months' => 'integer',
            'amount_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
