<?php

declare(strict_types=1);

namespace App\Enums;

enum WhatsappDelivery: string
{
    case CopyCode = 'copy_code';
    case OneTap = 'one_tap';
}
