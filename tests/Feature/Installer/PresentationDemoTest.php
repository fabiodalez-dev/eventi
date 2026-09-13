<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventFeature;
use App\Models\User;
use App\Models\Venue;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\CategorySeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->city = testCity();
    freezeLocal($this->city, '2026-09-13 12:00');
    $this->seed(CategorySeeder::class);
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'ticketing_enabled' => true]);
});

it('previews the presentation catalog without modifying data', function (): void {
    $this->artisan('events:presentation-demo', ['city' => $this->city->slug, '--dry-run' => true])->assertSuccessful();
    expect(Event::count())->toBe(0)->and(EventFeature::count())->toBe(0);
});

it('enriches existing events and is resumable without changing reservations or dates', function (): void {
    Notification::fake();
    $date = occurrenceAtLocal($this->city, testCategory(), '2026-09-14 18:00', event: ['title' => 'Un incontro già prenotato', 'price_type' => 'free', 'booking_required' => true, 'description' => "Un incontro aperto.\n\nFotografia illustrativa: fonte https://example.test/photo"], venue: $this->venue);
    $date->update(['booking_enabled' => true, 'booking_capacity' => 10, 'booking_limit' => 6, 'booking_waitlist' => true]);
    $user = User::factory()->create();
    $booking = app(TicketingService::class)->reserve($user, $date, [['first_name' => 'Mario', 'last_name' => 'Rossi']], (string) Str::uuid(), false);
    Notification::fake();
    $password = $user->password;
    $options = ['city' => $this->city->slug, '--new' => 14, '--skip-images' => true];
    $this->artisan('events:presentation-demo', $options)->assertSuccessful();
    $ids = Event::orderBy('id')->pluck('id')->all();
    $starts = $date->fresh()->starts_at->toIso8601String();
    $this->artisan('events:presentation-demo', $options)->assertSuccessful();
    expect(Event::count())->toBe(15)->and(Event::orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and($date->fresh()->starts_at->toIso8601String())->toBe($starts)
        ->and($date->fresh()->booking_capacity)->toBe(10)
        ->and($date->event->fresh()->price_type->value)->toBe('free')
        ->and($booking->fresh()->status->value)->toBe('confirmed')
        ->and($user->fresh()->password)->toBe($password)
        ->and($date->event->fresh()->description)->toBe('Un incontro aperto.')
        ->and(count($date->event->fresh()->content_details['feature_ids']))->toBeGreaterThan(3)
        ->and(EventFeature::count())->toBeGreaterThan(40);
    foreach (Event::where('source_ref', 'like', 'investor-showcase-v2:%')->get() as $event) {
        expect($event->is_demo)->toBeTrue()->and($event->occurrences()->count())->toBe(1);
    }
    Notification::assertNothingSent();
});

it('prepares an actual waitlist using only demo accounts without sending notifications', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'biglietti@example.test']);
    User::factory()->create(['email' => 'attesa-ticket@example.test']);
    $category = Category::where('slug', 'corsi-e-workshop')->firstOrFail();
    $date = occurrenceAtLocal($this->city, $category, '2026-09-14 18:00', event: ['source_ref' => 'investor-showcase-v2:waiting'], venue: $this->venue);
    $options = ['city' => $this->city->slug, '--new' => 0, '--skip-images' => true, '--reservations' => true];
    $this->artisan('events:presentation-demo', $options)->assertSuccessful();
    $this->artisan('events:presentation-demo', $options)->assertSuccessful();
    expect(Booking::where('occurrence_id', $date->id)->count())->toBe(2)
        ->and(Booking::where('occurrence_id', $date->id)->where('status', 'waitlisted')->count())->toBe(1)
        ->and($date->fresh()->booking_capacity)->toBe(2);
    Notification::assertNothingSent();
});

it('replaces mismatched posters while retaining the originals and avoiding duplicate media', function (): void {
    Queue::fake();
    Storage::fake('public');
    $category = Category::where('slug', 'bambini-e-famiglie')->firstOrFail();
    $date = occurrenceAtLocal($this->city, $category, '2026-09-14 18:00', event: ['title' => 'Disegni giganti'], venue: $this->venue);
    $old = $date->event->addMedia(database_path('seeders/investor-media/altro.jpg'))->preservingOriginal()->toMediaCollection('poster');
    $options = ['city' => $this->city->slug, '--new' => 0];
    $this->artisan('events:presentation-demo', $options)->assertSuccessful();
    $poster = $date->event->fresh()->getFirstMedia('poster');
    expect($poster->getCustomProperty('credit.file'))->toBe('bambini-laboratorio.jpg')
        ->and($old->fresh()->collection_name)->toBe('presentation-previous-posters');
    $this->artisan('events:presentation-demo', $options)->assertSuccessful();
    expect($date->event->fresh()->getFirstMedia('poster')->id)->toBe($poster->id);
});
