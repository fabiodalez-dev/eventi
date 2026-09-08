<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('sponsorship_banners.web_enabled', true);
        $this->migrator->add('sponsorship_banners.android_enabled', true);
    }
};
