<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SponsorshipPlacement;
use App\Support\SecurityLog;
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

    /**
     * Ogni cambio del listino va nel registro di sicurezza, da qualunque
     * parte arrivi (pannello, app della redazione, eliminazione in gruppo):
     * è il prezzo che pagano i locali, e chi l'ha cambiato e da quanto a
     * quanto deve restare scritto.
     */
    protected static function booted(): void
    {
        static::created(fn (SponsorshipPrice $price) => $price->logChange('creata', null, (int) $price->amount_cents));
        static::updated(function (SponsorshipPrice $price): void {
            $original = $price->getOriginal('amount_cents');
            $price->logChange('modificata', is_numeric($original) ? (int) $original : null, (int) $price->amount_cents);
        });
        static::deleted(fn (SponsorshipPrice $price) => $price->logChange('eliminata', (int) $price->amount_cents, null));
    }

    private function logChange(string $change, ?int $before, ?int $after): void
    {
        $actor = auth()->user();
        $changed = array_values(array_diff(array_keys($this->getChanges()), ['updated_at']));
        SecurityLog::scrivi('listino_sponsorizzazioni_modificato', dettagli: array_filter([
            'voce_id' => (int) $this->getKey(),
            'operazione' => $change,
            'collocazione' => $this->placement->value,
            'mesi' => (int) $this->months,
            'prezzo_prima_centesimi' => $before,
            'prezzo_dopo_centesimi' => $after,
            'campi' => $change === 'modificata' ? $changed : null,
        ], fn (mixed $value): bool => $value !== null), causa: $actor instanceof User ? $actor : null);
    }

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
