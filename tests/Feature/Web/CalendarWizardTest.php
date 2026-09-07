<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory(['name' => 'Musica', 'slug' => 'musica']);
    freezeLocal($this->city, '2026-09-05 12:00:00');
});
afterEach(fn () => Carbon::setTestNow());

it('replaces the footer download with a public wizard', function (): void {
    $this->get('/')->assertOk()->assertSee(route('feeds.wizard'), false);
    $this->get('/calendario/personalizza')->assertOk()->assertSee('Musica')->assertSee('categories[]', false);
});

it('carries category and duration choices through to the actual ics', function (): void {
    $this->actingAs(User::factory()->create());
    occurrenceAtLocal($this->city, $this->category, '2026-09-06 20:00:00', event: ['title' => 'Concerto scelto']);
    occurrenceAtLocal($this->city, $this->category, '2026-10-06 20:00:00', event: ['title' => 'Concerto lontano']);
    $other = testCategory(['name' => 'Teatro', 'slug' => 'teatro']);
    occurrenceAtLocal($this->city, $other, '2026-09-06 20:00:00', event: ['title' => 'Spettacolo escluso']);
    $this->get('/calendario/personalizza?step=3&categories[]=musica&days=7')->assertOk()
        ->assertViewHas('preview', fn ($items) => $items->count() === 1)
        ->assertSee('category=musica', false)->assertSee('days=7', false)->assertDontSee('webcal:', false);
    $this->get('/eventi.ics?category=musica&days=7')->assertOk()
        ->assertSee('Concerto scelto')->assertDontSee('Concerto lontano')->assertDontSee('Spettacolo escluso');
});

it('rejects unknown categories and invalid horizons', function (): void {
    $this->actingAs(User::factory()->create());
    $this->getJson('/calendario/personalizza?categories[]=inesistente')->assertUnprocessable();
    $this->getJson('/eventi.ics?days=999')->assertUnprocessable();
});

it('explains ongoing subscriptions before choosing the calendar horizon', function (): void {
    $this->get('/calendario/personalizza?step=2&categories[]=musica')->assertOk()
        ->assertSee(__('subscriptions.continuous_title'))
        ->assertSee(__('subscriptions.continuous_help'))
        ->assertSee(__('subscriptions.continuous_timing'))
        ->assertSee(__('subscriptions.horizon_help'))
        ->assertSee('aria-describedby="calendar-horizon-help"', false);
});
