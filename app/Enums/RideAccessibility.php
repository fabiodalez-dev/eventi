<?php

declare(strict_types=1);

namespace App\Enums;

enum RideAccessibility: string
{
    case NotSpecified = 'not_specified';
    case FoldingChair = 'folding_chair';
    case WheelchairSpace = 'wheelchair_space';

    public function label(): string
    {
        return __('carpool.RideAccessibility.'.$this->value);
    }
}
