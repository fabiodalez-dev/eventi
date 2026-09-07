<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-07 12:00');
});
afterEach(fn () => Carbon::setTestNow());

it('requires login for all calendar export routes including the old public URL', function (): void {
    $this->get('/eventi.ics')->assertRedirect(route('login'));
    $this->get('/padova/eventi.ics')->assertRedirect(route('login'));
    $this->getJson('/api/v1/me/calendar/export')->assertUnauthorized();
    $this->get('/calendario/personalizza?step=3')->assertOk()->assertDontSee('webcal:')->assertDontSee('calendar.google.com');
});

it('exports authenticated filtered dates with stable IDs and no public subscription URL', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $item = occurrenceAtLocal($this->city, $this->category, '2026-09-08 21:00', '2026-09-08 23:00', event: ['title' => 'Concerto scelto']);
    $other = testCategory(['name' => 'Teatro']);
    occurrenceAtLocal($this->city, $other, '2026-09-08 19:00', event: ['title' => 'Non selezionato']);
    $draft = occurrenceAtLocal($this->city, $this->category, '2026-09-08 19:00', event: ['status' => EventStatus::Draft]);
    $response = $this->getJson('/api/v1/me/calendar/export?days=7&category='.$this->category->slug)->assertOk();
    $response->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.id', $item->id)
        ->assertJsonPath('data.events.0.start', $item->starts_at->getTimestamp() * 1000);
    expect($response->json('data.ics'))->toContain('Concerto scelto')->not->toContain('Non selezionato');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $item->event->update(['status' => EventStatus::Draft]);
    $this->getJson('/api/v1/me/calendar/export?category='.$this->category->slug)->assertOk()->assertJsonCount(0, 'data.events');
});

it('represents all-day dates at UTC midnight with exclusive end for Android', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $item = occurrenceAtLocal($this->city, $this->category, '2026-09-08 00:00', '2026-09-09 00:00');
    $item->update(['is_all_day' => true]);
    $this->getJson('/api/v1/me/calendar/export')->assertOk()
        ->assertJsonPath('data.events.0.allDay', true)
        ->assertJsonPath('data.events.0.timezone', 'UTC')
        ->assertJsonPath('data.events.0.start', Carbon::parse('2026-09-08', 'UTC')->getTimestamp() * 1000)
        ->assertJsonPath('data.events.0.end', Carbon::parse('2026-09-09', 'UTC')->getTimestamp() * 1000);
});
