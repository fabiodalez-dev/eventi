<?php

declare(strict_types=1);

namespace App\Enums;

enum PromotionMode: string
{
    case Automatic = 'automatic';
    case Selected = 'selected';

    public function label(): string
    {
        return __('promotions.mode.'.$this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(fn (self $mode): array => ['value' => $mode->value, 'label' => $mode->label()], self::cases()), 'label', 'value');
    }
}
