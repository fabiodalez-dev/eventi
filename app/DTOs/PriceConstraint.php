<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PriceMode;
use App\Queries\EventOccurrenceQuery;

/**
 * Il parametro `price` di §13.2 — `free|donation|paid|max:20` — letto come
 * oggetto invece che come stringa.
 *
 * Non coincide con `PriceFilter`, che è l'insieme chiuso delle quattro voci
 * offerte dal sito (§11.3): l'API accetta un tetto qualsiasi, quindi il filtro
 * è un modo più un importo. Come sempre, qui non si interroga niente: si dice
 * al motore quale metodo chiamare.
 */
final readonly class PriceConstraint
{
    private function __construct(
        public PriceMode $mode,
        public ?float $amount = null,
    ) {}

    /**
     * `null` quando il valore non è riconoscibile: chi valida la richiesta
     * decide se sia un 422 o un filtro da ignorare.
     */
    public static function parse(string $value): ?self
    {
        $value = mb_strtolower(trim($value));

        if (str_starts_with($value, PriceMode::Max->value.':')) {
            $amount = mb_substr($value, mb_strlen(PriceMode::Max->value) + 1);

            return is_numeric($amount) && (float) $amount >= 0
                ? new self(PriceMode::Max, (float) $amount)
                : null;
        }

        $mode = PriceMode::tryFrom($value);

        return $mode === null || $mode === PriceMode::Max ? null : new self($mode);
    }

    public function applyTo(EventOccurrenceQuery $query): EventOccurrenceQuery
    {
        return match ($this->mode) {
            PriceMode::Free => $query->priceFree(),
            PriceMode::Donation => $query->priceDonation(),
            PriceMode::Paid => $query->pricePaid(),
            PriceMode::Max => $query->priceMax($this->amount ?? 0.0),
        };
    }
}
