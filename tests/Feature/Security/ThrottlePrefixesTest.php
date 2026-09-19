<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Notification::fake();
    $this->city = testCity();
    $this->event = Event::factory()->for($this->city)->published()->create();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $this->user = User::factory()->create();
    $this->comments = '/api/v1/events/'.$this->event->slug.'/comments';
});

/** Esaurisce il contatore dei commenti via API: dieci scritture l'ora, l'undicesima è 429. */
function exhaustEventComments(string $url): void
{
    foreach (range(1, 10) as $i) {
        test()->postJson($url, ['body' => 'Commento numero '.$i.' sulla serata'])->assertCreated();
    }
    test()->postJson($url, ['body' => 'Commento di troppo sulla serata'])->assertTooManyRequests();
}

it('never leaves a numeric throttle without the name of its action', function (): void {
    // Senza terzo argomento Laravel chiave il contatore sul solo utente (o IP): tutte le
    // rotte con gli stessi numeri finirebbero nello stesso secchio. Le rotte dei pacchetti
    // (l'upload di Livewire) non sono nostre e non si toccano.
    $offenders = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->getActionName(), 'App\\') || $route->getActionName() === 'Closure')
        ->flatMap(fn ($route) => collect($route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware) && preg_match('/^throttle:\d+,\d+$/', $middleware) === 1)
            ->map(fn ($middleware) => implode('|', $route->methods()).' '.$route->uri().' '.$middleware))
        ->values()->all();

    expect($offenders)->toBe([], 'Throttle senza prefisso: '.implode(', ', $offenders));
});

it('keeps separate counters for comments, reviews and bookings of the same user', function (): void {
    Sanctum::actingAs($this->user);
    exhaustEventComments($this->comments);

    $review = $this->postJson('/api/v1/venues/'.$this->venue->slug.'/reviews', ['rating' => 5, 'body' => 'Un locale molto piacevole, ci torno.']);
    expect($review->status())->not->toBe(429);
    $occurrence = occurrenceAt($this->city, testCategory(), '2026-12-06 18:00:00');
    $booking = $this->postJson('/api/v1/occurrences/'.$occurrence->id.'/bookings', []);
    expect($booking->status())->not->toBe(429);
});

it('shares the same action budget between the website and the API', function (): void {
    Sanctum::actingAs($this->user);
    exhaustEventComments($this->comments);

    $this->actingAs($this->user, 'web')
        ->post(route('events.comments.store', $this->event->slug), ['body' => 'Dal sito, dopo aver finito il budget'])
        ->assertTooManyRequests();
});
