<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\SavedEvent;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    $this->user = User::factory()->create();
    freezeLocal($this->city, '2026-09-10 18:00');
});
afterEach(fn () => Carbon::setTestNow());

it('requires authentication and never exposes a public saved calendar', function (): void {
    $this->get('/i-miei-salvataggi/calendario.ics')->assertRedirect(route('login'));
    $this->getJson('/api/v1/me/saved/calendar')->assertUnauthorized();
});

it('exports only the current users dates including past dates without pagination', function (): void {
    $other = User::factory()->create();
    foreach (range(1, 31) as $number) {
        $item = occurrenceAtLocal($this->city, $this->category, '2026-09-12 19:00', event: ['title' => "Salvato numero $number"]);
        SavedEvent::query()->create(['user_id' => $this->user->id, 'occurrence_id' => $item->id]);
    }
    $past = occurrenceAtLocal($this->city, $this->category, '2026-09-01 19:00', event: ['title' => 'Data passata']);
    SavedEvent::query()->create(['user_id' => $this->user->id, 'occurrence_id' => $past->id]);
    $private = occurrenceAtLocal($this->city, $this->category, '2026-09-13 19:00', event: ['title' => 'Salvataggio altrui']);
    SavedEvent::query()->create(['user_id' => $other->id, 'occurrence_id' => $private->id]);
    $response = $this->actingAs($this->user)->get('/i-miei-salvataggi/calendario.ics?user_id='.$other->id.'&mese=2027-01');
    $response->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertSee('Data passata')->assertDontSee('Salvataggio altrui');
    expect(substr_count($response->getContent(), 'BEGIN:VEVENT'))->toBe(32);
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
});

it('excludes drafts even if an old saved relation exists', function (): void {
    $item = occurrenceAtLocal($this->city, $this->category, '2026-09-12 19:00', event: ['title' => 'Bozza segreta']);
    SavedEvent::query()->create(['user_id' => $this->user->id, 'occurrence_id' => $item->id]);
    $item->event->update(['status' => EventStatus::Draft]);
    $this->actingAs($this->user)->get('/i-miei-salvataggi/calendario.ics')->assertOk()->assertDontSee('Bozza segreta');
});

it('offers both calendar actions in saved and a visible home banner', function (): void {
    $this->actingAs($this->user)->get('/i-miei-salvataggi')->assertOk()
        ->assertSee(route('account.saved.calendar'), false)->assertSee(route('feeds.wizard'), false);
    $this->get('/')->assertOk()->assertSee('data-calendar-banner', false)->assertSee(__('subscriptions.banner_title'));
});

it('returns the same calendar securely to the authenticated app', function (): void {
    $item = occurrenceAtLocal($this->city, $this->category, '2026-09-12 19:00', event: ['title' => 'Solo il mio concerto']);
    SavedEvent::query()->create(['user_id' => $this->user->id, 'occurrence_id' => $item->id]);
    Sanctum::actingAs($this->user);
    $response = $this->getJson('/api/v1/me/saved/calendar')->assertOk();
    expect($response->json('data.ics'))->toContain('BEGIN:VCALENDAR', 'Solo il mio concerto');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
});
