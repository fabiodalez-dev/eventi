<?php

declare(strict_types=1);

namespace App\Enums;

enum LineupRole: string
{
    case Live = 'live';
    case Dj = 'dj';
    case Opening = 'opening';
    case SpecialGuest = 'special_guest';
    case Speaker = 'speaker';

    public function label(): string
    {
        return __('enums.lineup_role.'.$this->value);
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
