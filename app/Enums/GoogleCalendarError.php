<?php

declare(strict_types=1);

namespace App\Enums;

enum GoogleCalendarError: string
{
    case Authorization = 'authorization';
    case Sync = 'sync';
}
