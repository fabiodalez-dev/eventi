<?php

declare(strict_types=1);

namespace App\Enums;

enum AdmissionStatus: string
{
    case Valid = 'valid';
    case Waitlisted = 'waitlisted';
    case CheckedIn = 'checked_in';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
