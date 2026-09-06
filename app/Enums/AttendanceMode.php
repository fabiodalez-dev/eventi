<?php

declare(strict_types=1);

namespace App\Enums;

enum AttendanceMode: string
{
    case Offline = 'offline';
    case Online = 'online';
    case Mixed = 'mixed';

    public function schema(): string
    {
        return 'https://schema.org/'.match ($this) {
            self::Offline => 'OfflineEventAttendanceMode', self::Online => 'OnlineEventAttendanceMode',
            self::Mixed => 'MixedEventAttendanceMode',
        };
    }
}
