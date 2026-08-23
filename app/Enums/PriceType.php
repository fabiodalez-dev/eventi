<?php

declare(strict_types=1);

namespace App\Enums;

enum PriceType: string
{
    case Free = 'free';
    case Donation = 'donation';
    case Ticket = 'ticket';
    case Membership = 'membership';
    case Unknown = 'unknown';

    public function label(): string
    {
        return __('enums.price_type.'.$this->value);
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
