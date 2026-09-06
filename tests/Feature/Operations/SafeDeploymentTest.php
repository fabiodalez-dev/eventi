<?php

declare(strict_types=1);

use App\Support\ReleaseManifest;
use Illuminate\Support\Facades\Cache;

it('accepts only a successful CI manifest for the exact source revision', function (): void {
    $sha = str_repeat('a', 40);
    $manifest = ['sha' => $sha, 'checks' => 'passed', 'run_id' => '123'];
    expect(ReleaseManifest::matches($manifest, $sha))->toBeTrue();
    foreach ([null, [], ['sha' => $sha], [...$manifest, 'checks' => 'failed'], [...$manifest, 'sha' => str_repeat('b', 40)], [...$manifest, 'run_id' => ''], [...$manifest, 'run_id' => 'invalid']] as $invalid) {
        expect(ReleaseManifest::matches($invalid, $sha))->toBeFalse();
    }
    expect(ReleaseManifest::matches($manifest, '--upload-pack'))->toBeFalse();
});

it('never deploys over an existing CLI or HTTP deployment lock', function (): void {
    $lock = Cache::lock('deploy:execution', 3600);
    $lock->get();
    try {
        $this->artisan('deploy:pull')->expectsOutputToContain('Un rilascio è già in corso.')->assertFailed();
    } finally {
        $lock->release();
    }
});

it('does not mutate a development installation', function (): void {
    $this->artisan('deploy:pull')->expectsOutputToContain('solo in produzione')->assertFailed();
});

it('reports only deployment identity and never caches it', function (): void {
    $this->get('/release-status')->assertOk()->assertJsonStructure(['sha', 'deployed_at'])
        ->assertDontSee('APP_KEY')->assertHeader('X-Robots-Tag', 'noindex');
    expect($this->get('/release-status')->headers->get('Cache-Control'))->toContain('no-store');
});

it('gates production on the full test suite including Android and Lighthouse', function (): void {
    $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));
    expect($workflow)->toContain('needs: [quality, tests, lighthouse, android]', 'name: tested-build', 'EXPECTED_SHA:', 'cancel-in-progress: false')
        ->not->toContain('continue-on-error: true', 'assembleRelease');
});
