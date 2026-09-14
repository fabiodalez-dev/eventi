<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->update(
            'frontend_cache.ttl_minutes',
            static fn (mixed $minutes): int => max(30, (int) $minutes),
        );
    }
};
