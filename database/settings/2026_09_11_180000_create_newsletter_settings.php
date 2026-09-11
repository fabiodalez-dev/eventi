<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        foreach (['weekday' => 4, 'time' => '16:00', 'max_items' => 8] as $key => $default) {
            $this->migrator->add('newsletter.'.$key, config('notifications.digests.weekend.'.$key, $default));
        }
    }
};
