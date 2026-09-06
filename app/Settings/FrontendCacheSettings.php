<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class FrontendCacheSettings extends Settings
{
    public bool $managed;

    public bool $enabled;

    public string $store;

    public int $ttl_minutes;

    public string $redis_host;

    public int $redis_port;

    public int $redis_database;

    public ?string $redis_username;

    public ?string $redis_password;

    public bool $redis_tls;

    public static function group(): string
    {
        return 'frontend_cache';
    }

    /** @return list<string> */
    public static function encrypted(): array
    {
        return ['redis_password'];
    }
}
