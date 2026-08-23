<?php

declare(strict_types=1);

namespace App\Enums;

enum FollowableType: string
{
    case Venue = 'venue';
    case Tag = 'tag';
    case Category = 'category';

    public function label(): string
    {
        return __('enums.followable_type.'.$this->value);
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
