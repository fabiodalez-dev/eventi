<?php

declare(strict_types=1);

namespace App\Enums;

enum CarpoolCaseStatus: string
{
    case Open = 'open';
    case Assigned = 'assigned';
    case Waiting = 'waiting';
    case Resolved = 'resolved';

    public function label(): string
    {
        return __('carpool.CarpoolCaseStatus.'.$this->value);
    }
}
