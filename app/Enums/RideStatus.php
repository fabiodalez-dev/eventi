<?php

declare(strict_types=1);

namespace App\Enums;

enum RideStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return __('carpool.RideStatus.'.$this->value);
    }
}
