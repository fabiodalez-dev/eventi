<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationDelivery: string
{
    case Auto = 'auto';
    case Mail = 'mail';
    case Push = 'push';
    case Both = 'both';
    case Database = 'database';
}
