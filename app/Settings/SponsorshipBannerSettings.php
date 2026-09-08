<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class SponsorshipBannerSettings extends Settings
{
    public bool $web_enabled;

    public bool $android_enabled;

    public static function group(): string
    {
        return 'sponsorship_banners';
    }
}
