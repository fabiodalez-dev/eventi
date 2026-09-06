<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        foreach (['managed' => false, 'enabled' => true, 'store' => 'frontend-file', 'ttl_minutes' => 1,
            'redis_host' => '127.0.0.1', 'redis_port' => 6379, 'redis_database' => 2,
            'redis_username' => null, 'redis_tls' => false] as $key => $value) {
            $this->migrator->add('frontend_cache.'.$key, $value);
        }
        $this->migrator->addEncrypted('frontend_cache.redis_password', null);
    }
};
