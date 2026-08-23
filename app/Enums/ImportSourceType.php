<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportSourceType: string
{
    case Ics = 'ics';
    case Json = 'json';
    case Rss = 'rss';
    case Api = 'api';
    case Manual = 'manual';

    public function label(): string
    {
        return __('enums.import_source_type.'.$this->value);
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
