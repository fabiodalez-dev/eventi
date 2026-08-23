<?php

declare(strict_types=1);

namespace App\Enums;

enum VenueRole: string
{
    case Owner = 'owner';
    case Editor = 'editor';

    public function label(): string
    {
        return __('enums.venue_role.'.$this->value);
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
