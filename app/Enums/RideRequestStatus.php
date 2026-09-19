<?php

declare(strict_types=1);

namespace App\Enums;

enum RideRequestStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('carpool.RideRequestStatus.'.$this->value);
    }
}
