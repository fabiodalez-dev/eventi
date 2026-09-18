<?php

declare(strict_types=1);

use App\Actions\Comments\PostComment;
use App\Enums\EventCommentStatus;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
    $city = testCity();
    $this->event = Event::factory()->for($city)->published()->create();
    $this->author = User::factory()->create(['name' => '<svg/onload=window.commentXss=1>']);
    $this->payload = '<script data-xss-probe>window.commentXss=1</script><img data-xss-probe src=x onerror="window.commentXss=1"><svg data-xss-probe onload="window.commentXss=1"></svg></textarea><input data-xss-probe autofocus onfocus="window.commentXss=1">';
});

it('renders form submissions as text without executing XSS even without script CSP', function (bool $csp): void {
    config()->set('security.script_src.enabled', $csp);
    $this->actingAs($this->author);
    $page = visit(route('events.show', ['slug' => $this->event->slug]));
    $page->script('document.querySelector("[data-consent-banner]")?.remove()');
    $page->fill('#commenti > form textarea[name="body"]', $this->payload)
        ->click('#commenti > form button[type="submit"]')
        ->assertSee($this->payload);
    expect($page->script('window.commentXss ?? null'))->toBeNull();
    expect($page->script('document.querySelectorAll("[data-xss-probe], #commenti svg[onload]").length'))->toBe(0);
    expect(EventComment::query()->firstOrFail()->body)->toBe($this->payload);

    // Validation must also escape old input inside the textarea.
    $invalid = $this->payload.str_repeat('a', 2000);
    $page->script('document.querySelector("#commenti > form textarea").removeAttribute("maxlength")');
    $page->fill('#commenti > form textarea[name="body"]', $invalid)
        ->click('#commenti > form button[type="submit"]');
    expect($page->script('document.querySelector("#commenti > form textarea").value'))->toBe($invalid);
    expect($page->script('window.commentXss ?? null'))->toBeNull();
    expect($page->script('document.querySelectorAll("[data-xss-probe]").length'))->toBe(0);
})->with(['CSP enabled' => true, 'CSP disabled' => false]);

it('does not execute stored XSS in the admin table or full moderation details', function (): void {
    $comment = app(PostComment::class)->handle($this->event, $this->author, $this->payload);
    $comment->forceFill(['status' => EventCommentStatus::Hidden, 'moderation_note' => $this->payload])->save();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    $page = visit('/admin/event-comments');
    expect($page->script('window.commentXss ?? null'))->toBeNull();
    expect($page->script('document.querySelectorAll("[data-xss-probe], svg[onload]").length'))->toBe(0);
    $page->click('Leggi e verifica')->assertSee($this->payload);
    expect($page->script('window.commentXss ?? null'))->toBeNull();
    expect($page->script('document.querySelectorAll("[data-xss-probe], svg[onload]").length'))->toBe(0);
});
