<?php

declare(strict_types=1);

namespace App\Enums;

enum PostIntent: string
{
    case Recommend = 'recommend';
    case Attend = 'attend';
}
