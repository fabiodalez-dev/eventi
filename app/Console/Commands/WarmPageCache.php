<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Middleware\CachePage;
use App\Models\City;
use App\Services\Cache\FrontendCacheConfiguration;
use App\Support\CurrentCity;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class WarmPageCache extends Command
{
    protected $signature = 'page-cache:warm';

    protected $description = 'Rigenera le pagine pubbliche più visitate';

    /** @var list<string> */
    private const PATHS = ['/', '/eventi', '/eventi/oggi', '/eventi/weekend', '/locali', '/organizzatori'];

    public function handle(Kernel $kernel, CachePage $pageCache, FrontendCacheConfiguration $configuration): int
    {
        $configuration->apply();

        if (! config()->boolean('page_cache.enabled')) {
            $this->info('Cache di pagina disattivata.');

            return self::SUCCESS;
        }

        $city = City::query()->active()->orderBy('id')->first();
        if ($city === null) {
            $this->warn('Nessuna città attiva.');

            return self::SUCCESS;
        }

        app(CurrentCity::class)->set($city);
        $store = Cache::store(config('page_cache.store') ?: null);
        $warmed = 0;

        foreach (self::PATHS as $path) {
            $request = Request::create(url($path), 'GET');

            try {
                // A normal request would hit the existing entry and leave its
                // original expiration untouched. Removing this exact key makes
                // the scheduled request genuinely renew the hot page.
                $store->forget($pageCache->key($request));
                $response = $kernel->handle($request);

                if ($response->getStatusCode() === 200 && $response->headers->get('X-Page-Cache') === 'miss') {
                    $warmed++;
                } else {
                    $this->warn(sprintf('%s -> %d', $path, $response->getStatusCode()));
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->error(sprintf('%s -> %s', $path, $exception->getMessage()));
            } finally {
                if (isset($response)) {
                    $kernel->terminate($request, $response);
                }
                unset($response);
            }
        }

        $this->info(sprintf('%d pagine rigenerate.', $warmed));

        return $warmed === count(self::PATHS) ? self::SUCCESS : self::FAILURE;
    }
}
