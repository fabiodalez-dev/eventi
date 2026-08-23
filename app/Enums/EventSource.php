<?php

declare(strict_types=1);

namespace App\Enums;

enum EventSource: string
{
    case Manual = 'manual';
    case Venue = 'venue';
    case Submission = 'submission';
    case ImportIcs = 'import_ics';
    case ImportApi = 'import_api';

    public function label(): string
    {
        return __('enums.event_source.'.$this->value);
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
