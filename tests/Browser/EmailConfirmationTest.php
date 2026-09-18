<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use App\Notifications\VerifyEmailLink;
use Illuminate\Support\Facades\Notification;

it('shows a readable light confirmation screen on mobile and desktop and returns to comments', function (): void {
    Notification::fake();
    $city = testCity();
    $event = Event::factory()->for($city)->published()->create();
    $user = User::factory()->unverified()->create(['appearance' => 'light', 'email' => 'giulia@example.test']);
    $this->actingAs($user);
    $destination = route('events.show', ['slug' => $event->slug]).'#commenti';
    $page = visit(route('verification.notice', ['intended' => $destination]))->inLightMode()->resize(390, 844);
    $page->script('document.querySelector("[data-consent-banner]")?.remove()');
    $page->assertSee('giulia@example.test')->assertSee(__('account.verify.title'));
    expect($page->script('document.documentElement.dataset.theme'))->toBe('light');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(filename: 'email-confirmation-mobile');
    $page->resize(1280, 900)->screenshot(filename: 'email-confirmation-desktop');
    $page->click('form[action$="/email/verifica"] button')->assertSee(__('account.verify.sent'));
    Notification::assertSentToTimes($user, VerifyEmailLink::class, 1);
    $page = visit(VerifyEmailLink::url($user));
    $page->assertSee(__('community.whatsapp.lead'));
    $page->click(__('community.whatsapp.later'));
    $page->assertVisible('#commenti > form textarea[name="body"]');
    expect($page->script('window.location.pathname'))->toBe('/eventi/'.$event->slug);
    expect($page->script('window.location.hash'))->toBe('#commenti');
});
