<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    testCity();
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
});

it('registers corrects validation errors saves the profile and logs in again', function (string $device): void {
    $page = visit('/registrati')->on()->{$device}()
        ->click('[data-consent-banner] button[value="reject_all"]')
        ->fill('first_name', 'Anna')->fill('last_name', 'Rossi')->fill('email', 'browser-auth@example.test')
        ->fill('password', 'A-valid-password-123')->fill('password_confirmation', 'mismatch');
    // Submit once: Pest's retry wrapper can replay a successful navigation.
    $page->page()->locator('form[action$="/registrati"] button[type="submit"]')->click(['timeout' => 5000]);
    $page->assertVisible('input[name="password"][aria-invalid="true"]');
    expect(User::query()->where('email', 'browser-auth@example.test')->exists())->toBeFalse();
    $page->fill('password', 'A-valid-password-123')->fill('password_confirmation', 'A-valid-password-123');
    if ($device === 'mobile') {
        $page->script('document.querySelector("form[action$=\\"/registrati\\"] button[type=submit]").addEventListener("click", () => { const until = performance.now() + 1200; while (performance.now() < until) {} });');
    }
    $page->page()->locator('form[action$="/registrati"] button[type="submit"]')->click(['timeout' => 5000]);
    $page->assertSee(__('account.register.done'));
    $user = User::query()->where('email', 'browser-auth@example.test')->sole();
    expect($user->hasRole('user'))->toBeTrue()->and($user->email_verified_at)->toBeNull();
    $page->navigate('/il-mio-profilo')->fill('name', 'Anna aggiornata');
    $page->page()->locator('form[action$="/il-mio-profilo"] button[type="submit"]:not(.bg-live)')->click(['timeout' => 5000]);
    $page->assertSee(__('account.profile.saved'));
    expect($user->fresh()->name)->toBe('Anna aggiornata')->and($user->fresh()->quiet_hours)->toBeNull();
    $page->page()->locator('[data-profile-logout] button[type="submit"]')->click(['timeout' => 5000]);
    $page->navigate('/il-mio-profilo')->assertPresent('form[action$="/accedi"]')
        ->fill('email', 'browser-auth@example.test')->fill('password', 'wrong-password');
    $page->page()->locator('form[action$="/accedi"] button[type="submit"]')->click(['timeout' => 5000]);
    $page->assertSee(__('account.login.failed'));
    $page->fill('password', 'A-valid-password-123');
    $page->page()->locator('form[action$="/accedi"] button[type="submit"]')->click(['timeout' => 5000]);
    $page->navigate('/il-mio-profilo')->assertSee('Area personale');
    expect($page->script('document.querySelector("input[name=name]").value'))->toBe('Anna aggiornata');
    $page->assertNoJavascriptErrors()->screenshot(filename: 'auth-profile-flow-'.$device);
})->with(['desktop', 'mobile']);
