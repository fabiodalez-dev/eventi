<?php

declare(strict_types=1);

namespace App\Services\Cache;

use App\Settings\FrontendCacheSettings;
use App\Support\ContentVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FrontendCacheConfiguration
{
    public function apply(): void
    {
        $settings = app(FrontendCacheSettings::class);
        if (! $settings->managed) {
            return;
        }
        config()->set('page_cache.enabled', $settings->enabled);
        config()->set('page_cache.store', $settings->store);
        config()->set('page_cache.ttl_minutes', $settings->ttl_minutes);
        config()->set('database.redis.frontend', [
            'host' => ($settings->redis_tls ? 'tls://' : '').$settings->redis_host,
            'port' => $settings->redis_port,
            'database' => $settings->redis_database,
            'username' => $settings->redis_username,
            'password' => $settings->redis_password,
            'timeout' => 1,
            'read_timeout' => 1,
            'max_retries' => 0,
        ]);
        config()->set('cache.stores.frontend-redis.stores', ['frontend-redis-primary', 'frontend-file']);
        config()->set('cache.stores.frontend-redis-primary', ['driver' => 'redis', 'connection' => 'frontend']);
    }

    /** Clear presentation data only. Never flush a shared cache containing locks. */
    public function clear(): void
    {
        Cache::forever('frontend_cache_revision', (string) Str::uuid());
        ContentVersion::bumpTaxonomies();
        Artisan::call('view:clear');
    }

    /**
     * Probe with an isolated connection; no Redis credentials enter public state or logs.
     *
     * @param  array<string, mixed>  $values
     */
    public function verifyRedis(array $values): bool
    {
        if (! extension_loaded('redis')) {
            return false;
        }
        $redis = new \Redis;
        try {
            $host = ($values['redis_tls'] ? 'tls://' : '').$values['redis_host'];
            $redis->connect($host, (int) $values['redis_port'], 1, null, 0, 1);
            if (filled($values['redis_password'] ?? null)) {
                $redis->auth(filled($values['redis_username'] ?? null)
                    ? [$values['redis_username'], $values['redis_password']] : $values['redis_password']);
            }
            $redis->select((int) $values['redis_database']);
            $key = 'incitta:frontend:probe:'.Str::uuid();
            $redis->setex($key, 10, 'ok');
            $result = $redis->get($key) === 'ok';
            $redis->del($key);

            return $result;
        } catch (\Throwable) {
            return false;
        } finally {
            try {
                $redis->close();
            } catch (\Throwable) {
                // A failed connection has no socket to close.
            }
        }
    }
}
