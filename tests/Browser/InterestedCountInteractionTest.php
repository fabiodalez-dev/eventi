<?php

declare(strict_types=1);

use App\Actions\Account\SaveOccurrences;
use App\Models\User;
use App\Support\EventUrl;

it('updates the visible interested count when a guest saves and removes a date', function (string $theme): void {
    $city = testCity();
    freezeLocal($city, '2026-09-14 12:00');
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-14 21:00', event: [
        'organizer_name' => 'Teatro Verdi',
        'organizer_url' => 'https://www.teatro-verdi.it',
    ]);
    foreach (User::factory()->count(2)->create() as $user) {
        app(SaveOccurrences::class)->one($user, $date);
    }

    $page = visit(EventUrl::occurrence($date))->{$theme}();
    $page->script('localStorage.removeItem("salvataggi"); localStorage.setItem("salvataggi.promemoria-nascosto", "1")');
    $page->refresh()
        ->click('[data-consent-banner] button[value="reject_all"]')
        ->assertSee('Organizzato da')
        ->assertSee('2 persone interessate')
        ->assertMissing('[data-interest-id="'.$date->id.'"] svg')
        ->click('[data-save-id="'.$date->id.'"] [data-save-button]')
        ->assertSee('3 persone interessate')
        ->refresh()
        ->assertSee('3 persone interessate')
        ->click('[data-save-id="'.$date->id.'"] [data-save-button]')
        ->assertSee('2 persone interessate');

    expect($page->script('() => document.querySelector("[data-interest-id=\\"'.$date->id.'\\"]").previousElementSibling.matches("[data-save]")'))->toBeTrue();
})->with(['inLightMode', 'inDarkMode']);

it('toggles a saved event from its photo badge without leaving the catalogue', function (string $theme): void {
    $city = testCity();
    freezeLocal($city, '2026-09-14 12:00');
    $date = occurrenceAtLocal($city, testCategory(), '2026-09-14 21:00');
    app(SaveOccurrences::class)->one(User::factory()->create(), $date);
    $page = visit('/eventi')->{$theme}();
    $page->script('localStorage.removeItem("salvataggi"); localStorage.setItem("salvataggi.promemoria-nascosto", "1")');
    $selector = '[data-interest-control][data-save-id="'.$date->id.'"] [data-save-button]';
    $page->refresh()->click('[data-consent-banner] button[value="reject_all"]')
        ->click($selector)->assertSee('2 persone interessate');
    expect($page->script('location.pathname'))->toBe('/eventi');
    expect($page->script('() => [...document.querySelectorAll(\'[data-save-id="'.$date->id.'"] [data-save-button]\')].every(button => button.getAttribute("aria-pressed") === "true")'))->toBeTrue();
    $page->refresh()->assertSee('2 persone interessate')
        ->click($selector)->assertSee('1 persona interessata');
    expect($page->script('location.pathname'))->toBe('/eventi');
})->with(['inLightMode', 'inDarkMode']);
