<?php

declare(strict_types=1);

namespace App\Enums;

use App\Queries\EventOccurrenceQuery;

/**
 * Le quattro voci del filtro prezzo di §11.3: gratis, offerta libera,
 * fino a 10 €, fino a 20 €.
 *
 * Non va confuso con `PriceType`, che è il dato scritto sull'evento: questo è
 * ciò che il pubblico chiede, e "fino a 20 €" comprende anche il gratuito.
 */
enum PriceFilter: string
{
    case Free = 'free';
    case Donation = 'donation';
    case Max10 = 'max10';
    case Max20 = 'max20';

    public function applyTo(EventOccurrenceQuery $query): EventOccurrenceQuery
    {
        return match ($this) {
            self::Free => $query->priceFree(),
            self::Donation => $query->priceDonation(),
            self::Max10 => $query->priceMax(10),
            self::Max20 => $query->priceMax(20),
        };
    }

    public function label(): string
    {
        return __('enums.price_filter.'.$this->value);
    }

    /**
     * Forma da infilare in un titolo: "Concerti gratis a Padova".
     */
    public function phrase(): string
    {
        return __('enums.price_filter_phrase.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
