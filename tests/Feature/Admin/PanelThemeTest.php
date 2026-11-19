<?php

declare(strict_types=1);

use App\Models\Organizer;
use Illuminate\Testing\TestResponse;
use Tests\Support\VenueIsolationScenario;

/**
 * I tre pannelli seguono il tema scelto sul sito (`/aspetto`).
 *
 * In testa a ogni pagina c'è lo script `theme-bootstrap`: scrive la scelta del
 * profilo dove Filament la legge (`localStorage.theme`) prima che la pagina si
 * disegni. Senza scelta nel profilo vale quella del browser (cookie
 * `incitta_appearance`), altrimenti il sistema. Il commutatore di Filament non
 * c'è: il tema si cambia in un posto solo.
 */
function panelThemeScript(TestResponse $response): ?string
{
    preg_match('/<script[^>]*data-panel-theme="([^"]*)"[^>]*>/', (string) $response->getContent(), $match);

    return $match[1] ?? null;
}

it('scrive nella pagina del pannello il tema del profilo, prima dello script di Filament', function (string $panel, ?string $appearance, string $expected): void {
    $scenario = VenueIsolationScenario::make();
    $user = $panel === 'admin' ? $scenario->admin : $scenario->ownerA;
    $user->forceFill(['appearance' => $appearance])->save();
    $url = match ($panel) {
        'admin' => '/admin',
        'venue' => '/gestione/'.$scenario->venueA->slug,
        'organizer' => '/organizza/'.Organizer::create(['name' => 'Collettivo Aurora', 'city_id' => $scenario->city->id, 'owner_id' => $user->id, 'is_active' => true])->slug,
    };

    $response = $this->actingAs($user->fresh())->get($url)->assertOk();
    $html = (string) $response->getContent();

    expect(panelThemeScript($response))->toBe($expected)
        ->and($html)->toContain("localStorage.setItem('theme', theme)")
        ->and($html)->toContain('<meta name="color-scheme" content="'.($expected === 'system' ? 'light dark' : $expected).'">')
        // Lo script di Filament che legge `localStorage.theme` viene dopo il nostro.
        ->and(strpos($html, 'data-panel-theme'))->toBeLessThan(strpos($html, 'const loadDarkMode'))
        // Nessun commutatore di Filament e nessun tema forzato nell'HTML.
        ->and($html)->not->toContain('fi-theme-switcher')
        ->and($html)->not->toContain("localStorage.setItem('theme', 'light')");
})->with([
    'redazione, scuro' => ['admin', 'dark', 'dark'],
    'redazione, chiaro' => ['admin', 'light', 'light'],
    'redazione, come il sistema' => ['admin', null, 'system'],
    'locale, scuro' => ['venue', 'dark', 'dark'],
    'locale, chiaro' => ['venue', 'light', 'light'],
    'organizzazione, scuro' => ['organizer', 'dark', 'dark'],
    'organizzazione, come il sistema' => ['organizer', null, 'system'],
]);

it('senza una scelta nel profilo usa quella fatta in questo browser', function (): void {
    $scenario = VenueIsolationScenario::make();
    $scenario->admin->forceFill(['appearance' => null])->save();

    $response = $this->actingAs($scenario->admin->fresh())
        ->withUnencryptedCookie('incitta_appearance', 'light')
        ->get('/admin')
        ->assertOk();

    expect(panelThemeScript($response))->toBe('light');
});

it('la scelta del profilo vale più di quella del browser', function (): void {
    $scenario = VenueIsolationScenario::make();
    $scenario->ownerA->forceFill(['appearance' => 'dark'])->save();

    $response = $this->actingAs($scenario->ownerA->fresh())
        ->withUnencryptedCookie('incitta_appearance', 'light')
        ->get('/gestione/'.$scenario->venueA->slug)
        ->assertOk();

    expect(panelThemeScript($response))->toBe('dark');
});

it('ignora un cookie con un valore che non è un tema', function (): void {
    $scenario = VenueIsolationScenario::make();
    $scenario->admin->forceFill(['appearance' => null])->save();

    $response = $this->actingAs($scenario->admin->fresh())
        ->withUnencryptedCookie('incitta_appearance', '"><script>alert(1)</script>')
        ->get('/admin')
        ->assertOk();

    expect(panelThemeScript($response))->toBe('system')
        ->and((string) $response->getContent())->not->toContain('<script>alert(1)</script>');
});

it('porta il nonce della CSP quando la pagina ne ha uno', function (): void {
    $scenario = VenueIsolationScenario::make();

    $response = $this->actingAs($scenario->admin)->get('/admin')->assertOk();
    preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $nonce);
    preg_match('/<script([^>]*)data-panel-theme=/', (string) $response->getContent(), $tag);

    expect($tag)->not->toBeEmpty();
    if (($nonce[1] ?? null) !== null) {
        expect($tag[1])->toContain('nonce="'.$nonce[1].'"');
    } else {
        expect($tag[1])->not->toContain('nonce=');
    }
});
