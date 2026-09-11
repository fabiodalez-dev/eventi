<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class NewsletterSettings extends Settings
{
    public int $weekday;

    public string $time;

    public int $max_items;

    public static function group(): string
    {
        return 'newsletter';
    }
}
