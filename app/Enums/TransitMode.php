<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Il mezzo con cui si arriva a un locale: la colonna sinistra di «Come
 * arrivare» (`venues.transit`).
 *
 * È un vocabolario chiuso e non testo libero perché quella colonna è
 * un'etichetta larga sei caratteri: senza un elenco, in sei mesi conterrebbe
 * «Metro», «metropolitana», «MM» e «M2» — quattro modi di dire la stessa cosa,
 * nessuno dei quali traducibile né filtrabile. Il numero della linea e la
 * fermata stanno nel testo della riga, che è il posto in cui una persona li
 * cerca.
 */
enum TransitMode: string
{
    case Metro = 'metro';
    case Tram = 'tram';
    case Bus = 'bus';
    case Train = 'train';
    case Shuttle = 'shuttle';
    case Car = 'car';
    case Parking = 'parking';
    case Bike = 'bike';
    case Walk = 'walk';

    public function label(): string
    {
        return __('enums.transit_mode.'.$this->value);
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
