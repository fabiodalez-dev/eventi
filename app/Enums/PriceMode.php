<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le forme del parametro `price` di §13.2: `free|donation|paid|max:20`.
 *
 * `Max` è l'unica che porta con sé un numero, ed è la ragione per cui il
 * filtro completo è un oggetto (`App\DTOs\PriceConstraint`) e non solo questo
 * enum: un tetto di spesa non è uno stato, è uno stato più un importo.
 */
enum PriceMode: string
{
    case Free = 'free';
    case Donation = 'donation';
    case Paid = 'paid';
    case Max = 'max';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
