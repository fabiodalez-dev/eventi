<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationChannel: string
{
    case Mail = 'mail';
    case Push = 'push';
    case Database = 'database';

    public function label(): string
    {
        return __('enums.notification_channel.'.$this->value);
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
