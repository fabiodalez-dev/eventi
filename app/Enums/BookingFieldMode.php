<?php

declare(strict_types=1);

namespace App\Enums;

enum BookingFieldMode: string
{
    case Hidden = 'hidden';
    case Optional = 'optional';
    case Required = 'required';
}
