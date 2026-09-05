<?php

declare(strict_types=1);

namespace App\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Waitlisted = 'waitlisted';
    case Cancelled = 'cancelled';
}
