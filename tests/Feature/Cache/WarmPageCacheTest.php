<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;

it('regenerates the hot pages so the next visitor gets a hit', function (): void {
    testCity();
    config(['page_cache.enabled' => true]);
    Cache::flush();

    $this->artisan('page-cache:warm')
        ->expectsOutput('6 pagine rigenerate.')
        ->assertSuccessful();

    foreach (['/', '/eventi', '/eventi/oggi', '/eventi/weekend', '/locali', '/organizzatori'] as $path) {
        $this->get($path)->assertOk()->assertHeader('X-Page-Cache', 'hit');
    }
});

it('does nothing successfully when the page cache is disabled', function (): void {
    config(['page_cache.enabled' => false]);

    $this->artisan('page-cache:warm')
        ->expectsOutput('Cache di pagina disattivata.')
        ->assertSuccessful();
});
