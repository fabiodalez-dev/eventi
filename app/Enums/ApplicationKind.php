<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Che cosa chiede una richiesta di accreditamento: un locale nuovo, un locale
 * già a catalogo da prendere in mano, o un organizzatore senza sede.
 */
enum ApplicationKind: string
{
    case Venue = 'venue';
    case Claim = 'claim';
    case Organizer = 'organizer';

    public function label(): string
    {
        return __('enums.application_kind.'.$this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
