<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\CachePerformance;
use App\Models\User;
use App\Services\Cache\FrontendCacheConfiguration;
use App\Settings\FrontendCacheSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('allows admins and denies ordinary users both the page and operations', function (): void {
    $this->actingAs($this->admin)->get('/admin/cache-performance')->assertOk();
    $this->actingAs(User::factory()->create())->get('/admin/cache-performance')->assertForbidden();
    expect(CachePerformance::canAccess())->toBeFalse();
});

it('saves frontend settings without changing sessions or the default cache', function (): void {
    $this->actingAs($this->admin);
    $store = config('cache.default');
    $session = config('session.driver');
    Livewire::test(CachePerformance::class)->fillForm([
        'managed' => true, 'enabled' => false, 'store' => 'frontend-file', 'ttl_minutes' => 2,
    ])->call('save')->assertHasNoFormErrors();
    app(FrontendCacheConfiguration::class)->apply();
    expect(config('page_cache.enabled'))->toBeFalse()
        ->and(config('page_cache.ttl_minutes'))->toBe(2)
        ->and(config('cache.default'))->toBe($store)
        ->and(config('session.driver'))->toBe($session);
});

it('encrypts Redis credentials and never hydrates the existing password into the form', function (): void {
    $settings = app(FrontendCacheSettings::class);
    $settings->redis_password = 'private-redis-secret';
    $settings->save();
    expect(DB::table('system_settings')->where('group', 'frontend_cache')->where('name', 'redis_password')->value('payload'))
        ->not->toContain('private-redis-secret');
    $this->actingAs($this->admin);
    Livewire::test(CachePerformance::class)->assertFormSet(['redis_password' => ''])
        ->call('save')->assertHasNoFormErrors();
    expect(app(FrontendCacheSettings::class)->redis_password)->toBe('private-redis-secret');
});

it('refuses to activate Redis if verification fails', function (): void {
    $this->mock(FrontendCacheConfiguration::class)->shouldReceive('verifyRedis')->once()->andReturnFalse();
    $this->actingAs($this->admin);
    Livewire::test(CachePerformance::class)->fillForm([
        'managed' => true, 'enabled' => true, 'store' => 'frontend-redis',
    ])->call('save')->assertHasFormErrors(['store']);
    expect(app(FrontendCacheSettings::class)->managed)->toBeFalse();
});

it('clears presentation caches but preserves locks and settings', function (): void {
    Cache::put('booking-lock', 'preserved', 60);
    $this->actingAs($this->admin);
    Livewire::test(CachePerformance::class)->call('clearCache');
    expect(Cache::get('booking-lock'))->toBe('preserved')
        ->and(Cache::get('frontend_cache_revision'))->toBeString()
        ->and(app(FrontendCacheSettings::class)->redis_port)->toBe(6379);
});
