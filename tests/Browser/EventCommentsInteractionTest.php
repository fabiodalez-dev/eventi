<?php

use App\Actions\Comments\PostComment;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

it('keeps parent reactions separate and confirms deletion under strict CSP', function (string $theme): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
    $city = testCity();
    freezeLocal($city, '2026-09-17 12:00');
    $event = Event::factory()->for($city)->published()->create();
    $user = User::factory()->create();
    $comment = app(PostComment::class)->handle($event, $user, 'Conversazione principale');
    $reply = app(PostComment::class)->handle($event, $user, 'Risposta indipendente', $comment);
    $this->actingAs($user);
    $page = visit($comment->permalink())->{$theme}();
    $page->script('document.querySelector("[data-consent-banner]")?.remove(); window.reviewViolations=[]; document.addEventListener("securitypolicyviolation",e=>window.reviewViolations.push(e.violatedDirective));');
    $parentSelector = '#commento-'.$comment->id.' > div form[data-reazione] input[value="love"] + button';
    $replySelector = '#commento-'.$reply->id.' form[data-reazione] input[value="love"] + button';
    $page->page()->locator($parentSelector)->click(['timeout' => 10000]);
    $page->assertAttribute($parentSelector, 'aria-pressed', 'true')->assertAttribute($replySelector, 'aria-pressed', 'false');
    $page->script('window.reviewConfirmed=false; window.confirm=()=>{window.reviewConfirmed=true;return false;}');
    $page->page()->locator('#commento-'.$comment->id.' > div form[data-conferma-eliminazione] button')->click(['timeout' => 10000]);
    expect($page->script('window.reviewConfirmed'))->toBeTrue();
    expect($page->script('window.reviewViolations'))->toBe([]);
    $page->refresh()->assertSee('Conversazione principale')->assertSee('Risposta indipendente');
})->with(['inLightMode', 'inDarkMode']);

it('offers the guest dialog only on event pages with a working login fallback', function (): void {
    $city = testCity();
    $event = Event::factory()->for($city)->published()->create();
    $page = visit(route('events.show', ['slug' => $event->slug]));
    $page->script('document.querySelector("[data-consent-banner]")?.remove()');
    $page->click('[data-apri-iscrizione]')->assertVisible('dialog[open]');
    $page->click('dialog button[type="submit"]')->assertMissing('dialog[open]');
    expect($page->script('document.querySelector("[data-apri-iscrizione]").tagName'))->toBe('A');
    $page->navigate('/')->assertMissing('#iscriviti-per-partecipare');
    $page->navigate('/accedi')->assertMissing('#iscriviti-per-partecipare');
});

it('returns a guest to the event comments after login from the dialog', function (): void {
    $city = testCity();
    $event = Event::factory()->for($city)->published()->create();
    $user = User::factory()->create();
    $page = visit(route('events.show', ['slug' => $event->slug]));
    $page->script('document.querySelector("[data-consent-banner]")?.remove()');
    $page->click('[data-apri-iscrizione]')->assertVisible('dialog[open]');
    $page->click('#iscriviti-per-partecipare a[href*="accedi"]')
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('form[action$="/accedi"] button[type="submit"]')
        ->assertVisible('#commenti textarea[name="body"]');
    expect($page->script('window.location.pathname'))->toBe('/eventi/'.$event->slug);
    expect($page->script('window.location.hash'))->toBe('#commenti');
});

it('sends ordinary login to the feed even after abandoning comment login', function (bool $abandoned): void {
    $city = testCity();
    $event = Event::factory()->for($city)->published()->create();
    $user = User::factory()->create();
    $page = visit(route('events.show', ['slug' => $event->slug]));
    $page->script('document.querySelector("[data-consent-banner]")?.remove()');
    if ($abandoned) {
        $page->click('[data-apri-iscrizione]')->click('#iscriviti-per-partecipare a[href*="accedi"]');
    }
    $page->navigate('/accedi')->assertMissing('#iscriviti-per-partecipare')
        ->fill('email', $user->email)->fill('password', 'password')
        ->click('form[action$="/accedi"] button[type="submit"]')
        ->assertPathIs('/il-mio-feed');
})->with(['direct login' => false, 'abandoned comment login' => true]);
