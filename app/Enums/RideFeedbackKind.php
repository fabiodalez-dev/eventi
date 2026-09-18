<?php

declare(strict_types=1);

namespace App\Enums;

enum RideFeedbackKind: string
{
    case Travelled = 'travelled';
    case Withdrew = 'withdrew';
    case NoShow = 'no_show';
    case Problem = 'problem';

    public function label(): string
    {
        return __('carpool.RideFeedbackKind.'.$this->value);
    }
}
