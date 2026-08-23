<?php

declare(strict_types=1);

namespace App\Enums;

enum DevicePlatform: string
{
    case Ios = 'ios';
    case Android = 'android';
    case Web = 'web';

    public function label(): string
    {
        return __('enums.device_platform.'.$this->value);
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
