<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cache\FrontendCacheConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class FrontendCacheCommand extends Command
{
    protected $signature = 'frontend:cache {--clear : Invalidate HTML without clearing sessions or locks}';

    protected $description = 'Verify the frontend cache store or invalidate cached HTML safely';

    public function handle(): int
    {
        app(FrontendCacheConfiguration::class)->apply();
        if ($this->option('clear')) {
            Cache::forever('frontend_cache_revision', (string) Str::uuid());
            $this->info('Frontend HTML invalidated. Sessions and locks preserved.');

            return self::SUCCESS;
        }

        $storeName = config('page_cache.store') ?: config('cache.default');
        $this->line('Enabled: '.(config('page_cache.enabled') ? 'yes' : 'no'));
        $this->line('Configured store: '.$storeName);
        $this->line('TTL minutes: '.config('page_cache.ttl_minutes'));
        $store = Cache::store($storeName);
        $key = 'frontend:probe:'.Str::uuid();

        try {
            $store->put($key, 'ok', 10);
            if ($store->get($key) !== 'ok') {
                $this->error('Cache read/write verification failed.');

                return self::FAILURE;
            }
            $this->info('Cache read/write verified. HTML edge caching is disabled for session safety.');
        } finally {
            $store->forget($key);
        }

        return self::SUCCESS;
    }
}
