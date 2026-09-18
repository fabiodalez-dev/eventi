<?php

declare(strict_types=1);

namespace App\Enums;

enum RideLeg: string
{
    case Outbound = 'outbound';
    case Return = 'return';

    public function label(): string
    {
        return __('carpool.RideLeg.'.$this->value);
    }
}
