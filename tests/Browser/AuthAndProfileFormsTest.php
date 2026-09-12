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
        ->fill('password', 'A-valid-password-123')->fill('password_confirmation', 'mismatch')
        ->click('form[action$="/registrati"] button[type="submit"]')
        ->assertVisible('input[name="password"][aria-invalid="true"]');
    expect(User::query()->where('email', 'browser-auth@example.test')->exists())->toBeFalse();
    $page->fill('password', 'A-valid-password-123')->fill('password_confirmation', 'A-valid-password-123')
        ->click('form[action$="/registrati"] button[type="submit"]')->assertSee(__('account.register.done'));
    $user = User::query()->where('email', 'browser-auth@example.test')->sole();
    expect($user->hasRole('user'))->toBeTrue()->and($user->email_verified_at)->toBeNull();
    $page->navigate('/il-mio-profilo')->fill('name', 'Anna aggiornata')
        ->fill('quiet_from', '23:00')->fill('quiet_to', '07:00')
        ->click('form[action$="/il-mio-profilo"] button[type="submit"]:not(.bg-live)')
        ->assertSee(__('account.profile.saved'));
    expect($user->fresh()->name)->toBe('Anna aggiornata')->and($user->fresh()->quiet_hours)->toBe(['from' => '23:00', 'to' => '07:00']);
    $page->click('[data-profile-logout] button[type="submit"]');
    $page->navigate('/il-mio-profilo')->assertPresent('form[action$="/accedi"]')
        ->fill('email', 'browser-auth@example.test')->fill('password', 'wrong-password')
        ->click('form[action$="/accedi"] button[type="submit"]')->assertSee(__('account.login.failed'));
    $page->fill('password', 'A-valid-password-123')->click('form[action$="/accedi"] button[type="submit"]');
    $page->navigate('/il-mio-profilo')->assertSee('Il mio profilo');
    expect($page->script('document.querySelector("input[name=name]").value'))->toBe('Anna aggiornata');
    $page->assertNoJavascriptErrors()->screenshot(filename: 'auth-profile-flow-'.$device);
})->with(['desktop', 'mobile']);
