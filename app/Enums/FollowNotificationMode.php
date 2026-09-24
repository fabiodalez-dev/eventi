<?php

declare(strict_types=1);

namespace App\Enums;

enum FollowNotificationMode: string
{
    case All = 'all';
    case NewOnly = 'new_only';
    case None = 'none';
}
