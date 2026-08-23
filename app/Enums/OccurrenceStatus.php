<?php

declare(strict_types=1);

namespace App\Enums;

enum OccurrenceStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
    case SoldOut = 'sold_out';
    case Postponed = 'postponed';
    case Moved = 'moved';

    public function label(): string
    {
        return __('enums.occurrence_status.'.$this->value);
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
