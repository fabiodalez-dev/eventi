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

it('shows city local time or all-day text in the saved calendar grid', function (string $date, bool $allDay): void {
    $item = occurrenceAtLocal($this->city, $this->category, $date.' 21:30');
    $item->update(['is_all_day' => $allDay]);
    SavedEvent::create(['user_id' => $this->user->id, 'occurrence_id' => $item->id]);
    $html = $this->actingAs($this->user)->get('/i-miei-salvataggi?vista=calendario&mese='.substr($date, 0, 7))->assertOk()->getContent();
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $labels = $xpath->query('//section[@data-saved-calendar]//a[contains(@class,"sm:block")]');
    expect($labels->length)->toBe(1);
    expect(trim($labels->item(0)->textContent))->toStartWith($allDay ? __('events.badge.all_day') : '21:30');
})->with([
    'summer time' => ['2026-09-12', false],
    'winter time' => ['2026-12-12', false],
    'all day' => ['2026-09-12', true],
]);

it('moves a finished date from agenda to past without waiting for midnight', function (): void {
    $past = occurrenceAtLocal($this->city, $this->category, '2026-09-10 14:00', '2026-09-10 16:00', event: ['title' => 'Finito oggi']);
    $ongoing = occurrenceAtLocal($this->city, $this->category, '2026-09-10 17:00', '2026-09-10 20:00', event: ['title' => 'Ancora in corso']);
    foreach ([$past, $ongoing] as $item) {
        SavedEvent::create(['user_id' => $this->user->id, 'occurrence_id' => $item->id]);
    }
    $this->actingAs($this->user)->get('/i-miei-salvataggi')->assertOk()->assertSee('Ancora in corso')->assertDontSee('Finito oggi')->assertSee('Passati');
    $this->get('/i-miei-salvataggi?passate=1')->assertOk()->assertSee('Finito oggi')->assertDontSee('Ancora in corso');
    $calendar = $this->get('/i-miei-salvataggi?vista=calendario')->assertOk()->assertDontSee('Finito oggi');
    $calendar->assertSee('href="'.route('events.show', $ongoing->event).'"', false);
    Sanctum::actingAs($this->user);
    $this->getJson('/api/v1/me/saved')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Ancora in corso');
    $this->getJson('/api/v1/me/saved?upcoming=0')->assertOk()->assertJsonCount(2, 'data');
});

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
