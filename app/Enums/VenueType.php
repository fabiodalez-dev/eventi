<?php

declare(strict_types=1);

namespace App\Enums;

enum VenueType: string
{
    case Bar = 'bar';
    case Pub = 'pub';
    case Circolo = 'circolo';
    case CentroSociale = 'centro_sociale';
    case Club = 'club';
    case Teatro = 'teatro';
    case Cinema = 'cinema';
    case Libreria = 'libreria';
    case Associazione = 'associazione';
    case Galleria = 'galleria';
    case SpazioPubblico = 'spazio_pubblico';
    case Ristorante = 'ristorante';
    case Altro = 'altro';

    public function label(): string
    {
        return __('enums.venue_type.'.$this->value);
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
