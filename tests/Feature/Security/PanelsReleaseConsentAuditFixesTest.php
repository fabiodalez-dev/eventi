<?php

declare(strict_types=1);

use App\Enums\ConsentCategory;
use App\Enums\UserRole;
use App\Filament\Venue\Pages\Collaborators;
use App\Models\ConsentScript;
use App\Models\User;
use App\Support\ReleaseManifest;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Support\VenueIsolationScenario;

/**
 * Correzioni dell'audit di novembre su pannelli durante l'impersonificazione,
 * ramo degli asset e script di consenso.
 */
it('rifiuta le azioni nei pannelli durante l\'impersonificazione e lascia sfogliare', function (): void {
    $scenario = VenueIsolationScenario::make();
    Notification::fake();
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);

    $this->actingAs($admin)->get(URL::temporarySignedRoute('impersonate.start', now()->addMinutes(5), ['user' => $scenario->ownerA->id]))->assertRedirect('/');
    $page = $this->get(Collaborators::getUrl(panel: 'venue', tenant: $scenario->venueA))->assertOk();
    $html = (string) $page->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $snapshots);
    $snapshot = collect($snapshots[1])->map(fn (string $raw): string => html_entity_decode($raw, ENT_QUOTES | ENT_HTML5))
        ->first(fn (string $json): bool => stripos((string) (json_decode($json, true)['memo']['name'] ?? ''), 'collaborators') !== false);
    expect($snapshot)->not->toBeNull();
    preg_match('/name="csrf-token" content="([^"]+)"/', $html, $token);
    $call = fn (string $method, array $params) => $this->withHeaders(['X-Livewire' => '1'])->postJson(app('livewire')->getUpdateUri(), [
        '_token' => $token[1] ?? csrf_token(),
        'components' => [['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]],
    ]);

    $call('mountAction', ['invite', [], ['table' => true]])->assertForbidden();
    $call('$refresh', [])->assertOk();
});

it('accetta nel ramo degli asset solo file statici', function (): void {
    expect(ReleaseManifest::firstNonStaticEntry("100644 blob a\tapp.css\n100644 blob b\tassets/app.js\n100644 blob c\trelease.json"))->toBeNull()
        ->and(ReleaseManifest::firstNonStaticEntry("100644 blob a\tapp.css\n100644 blob b\tprobe.php"))->toBe('probe.php')
        ->and(ReleaseManifest::firstNonStaticEntry("100644 blob a\tsub/.htaccess"))->toBe('sub/.htaccess')
        ->and(ReleaseManifest::firstNonStaticEntry("120000 blob a\tlink-env"))->toBe('link-env')
        ->and(ReleaseManifest::firstNonStaticEntry("100644 blob a\tbuild/x.php.abc"))->toBe('build/x.php.abc')
        ->and(ReleaseManifest::firstNonStaticEntry("100644 blob a\tx.phtml.txt"))->toBe('x.phtml.txt')
        ->and(ReleaseManifest::firstNonStaticEntry("100644 blob a\tLEGGIMI"))->toBe('LEGGIMI')
        ->and(ReleaseManifest::firstNonStaticEntry("100644 blob a\tfonts/inter.woff2\n100644 blob b\tassets/app-Bx3.js.map"))->toBeNull()
        ->and(ReleaseManifest::firstNonStaticEntry(null))->not->toBeNull();
});

it('non carica gli script di consenso allo staff collegato e li carica agli ospiti', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    ConsentScript::query()->create(['name' => 'Analisi', 'category' => ConsentCategory::Necessary, 'code' => 'window.__scriptDiProva = 1;', 'enabled' => true]);
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::SuperAdmin->value);

    expect((string) $this->get('/')->getContent())->toContain('window.__scriptDiProva');
    expect((string) $this->actingAs($admin)->get('/')->getContent())->not->toContain('window.__scriptDiProva');

    $persona = User::factory()->create();
    expect((string) $this->actingAs($persona)->withSession(['impersonator_id' => $admin->id])->get('/')->getContent())->not->toContain('window.__scriptDiProva');
});
