<?php

declare(strict_types=1);

namespace App\Enums;

enum MembershipRequirement: string
{
    case Required = 'required';
    case NotRequired = 'not_required';

    public function label(): string
    {
        return __('filters.membership.'.$this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
