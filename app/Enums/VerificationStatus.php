<?php

declare(strict_types=1);

namespace App\Enums;

enum VerificationStatus: string
{
    case Unverified = 'unverified';
    case VenueConfirmed = 'venue_confirmed';
    case EditorialChecked = 'editorial_checked';

    public function label(): string
    {
        return __('enums.verification_status.'.$this->value);
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
