<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialFormat: string
{
    case Portrait = 'portrait';
    case Square = 'square';
    case Story = 'story';

    public function height(): int
    {
        return match ($this) {
            self::Portrait => 1350, self::Square => 1080, self::Story => 1920
        };
    }
}
