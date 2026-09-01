<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lo stato di **una fascia di biglietto**, non dell'intera data.
 *
 * È la distinzione che `OccurrenceStatus::SoldOut` non sa fare: il parterre in
 * piedi può essere esaurito mentre il secondo anello è ancora in vendita, e
 * marcare esaurita l'intera serata sarebbe una bugia che allontana chi avrebbe
 * comprato il posto rimasto.
 */
enum TicketTierStatus: string
{
    case Available = 'available';
    case SoldOut = 'sold_out';
    case NotYetOnSale = 'not_yet_on_sale';
    case Closed = 'closed';

    public function label(): string
    {
        return __('enums.ticket_tier_status.'.$this->value);
    }

    /**
     * Se da questa fascia si può ancora comprare adesso. Governa il tono
     * dell'etichetta nella tabella dei biglietti e il pulsante di acquisto.
     */
    public function isOnSale(): bool
    {
        return $this === self::Available;
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
