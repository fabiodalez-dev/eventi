<?php

declare(strict_types=1);

namespace App\Enums;

enum AgeGroup: string
{
    case All = 'all';
    case Babies = '0-2';
    case Preschool = '3-5';
    case Children = '6-10';
    case Teens = '11-17';
    case Adults = '18-plus';

    public function label(): string
    {
        return __('family.ages.'.$this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $age): array => [$age->value => $age->label()])->all();
    }
}
