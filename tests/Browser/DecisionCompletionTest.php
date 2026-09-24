<?php

use App\Actions\Account\FollowSubject;
use App\Enums\FollowableType;
use App\Models\User;
use App\Services\Ticketing\TicketingService;
use App\Support\EventUrl;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    (new RolesAndPermissionsSeeder)->run();
    $this->city = testCity();
    $this->date = occurrenceAt($this->city, testCategory(), now()->addHour()->format('Y-m-d H:i:s'), null, ['booking_enabled' => true, 'booking_capacity' => 5]);
    $this->date->event->venue->update(['ticketing_enabled' => true]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('shows partial costs, missing facts and editable follow preferences without mobile overflow', function (string $device): void {
    $this->date->update(['cost_breakdown' => ['admission' => 10, 'drink' => 2]]);
    app(FollowSubject::class)($this->user, FollowableType::Venue, $this->date->event->venue_id);
    $page = visit(EventUrl::occurrence($this->date))->on()->{$device}()
        ->click('[data-consent-banner] button[value="reject_all"]')
        ->assertSee(__('decision.accessibility_unknown'))->assertSee(__('decision.costs'));
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    $page->navigate(route('account.feed'))->assertSee(__('decision.because_venue'))->assertSee(__('decision.show_all'))
        ->click('#feed-interests summary')->select('notification_mode', 'new_only');
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    // Let Pest's HTTP server process the form before waiting for its redirect.
    $page->page()->locator('form:has(select[name=notification_mode]) button[type=submit]')->click(['noWaitAfter' => true]);
    $page->assertSee(__('account.follow.stored'));
    expect($this->user->follows()->first()->notification_mode->value)->toBe('new_only');
    visit(route('account.feed'))->on()->{$device}()->assertSee(__('decision.because_venue'))
        ->click('#feed-interests summary')->assertValue('select[name=notification_mode]', 'new_only');
})->with(['desktop', 'mobile']);

it('keeps disconnected scans pending and retries a lost server reply without double admission', function (string $device): void {
    $this->date->checkinStaff()->attach($this->user);
    $booking = app(TicketingService::class)->reserve(User::factory()->create(), $this->date, ['Anna Rossi'], (string) Str::uuid(), false);
    $ticket = $booking->tickets->first();
    $page = visit(route('ticketing.manage.scanner', $this->date))->on()->{$device}()
        ->click('[data-consent-banner] button[value="reject_all"]');
    // First attempt cannot reach the server. Second commits but loses its reply.
    $page->script('(() => { const realFetch = window.fetch.bind(window); window.scanAttempt = 0; window.scanKeys = []; window.fetch = async (...args) => { if (args[1]?.method === "POST") { window.scanKeys.push(JSON.parse(args[1].body).request_key); window.scanAttempt++; if (window.scanAttempt === 1) throw new TypeError("offline"); const response = await realFetch(...args); if (window.scanAttempt === 2) throw new TypeError("reply lost"); return response; } return realFetch(...args); }; return true; })()');
    $page->fill('[data-ticket-scanner] [name="code"]', $ticket->code)->click('[data-ticket-scanner] button[type="submit"]')
        ->assertSee(__('decision.offline_pending'))->assertDontSee('Ingresso registrato: Anna Rossi.');
    expect($ticket->fresh()->checked_in_at)->toBeNull();
    $page->click('[data-checkin-retry]');
    $page->waitForEvent('networkidle');
    $page->click('[data-checkin-retry]')->assertSee('Ingresso registrato: Anna Rossi.');
    expect($page->script('window.scanKeys.length === 3 && new Set(window.scanKeys).size === 1'))->toBeTrue()
        ->and($ticket->fresh()->checked_in_at)->not->toBeNull()
        ->and($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
})->with(['desktop', 'mobile']);
