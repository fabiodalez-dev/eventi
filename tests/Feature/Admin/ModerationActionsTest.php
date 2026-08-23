<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\UserRole;
use App\Enums\VenueStatus;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Venues\Pages\EditVenue;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * §9.2 — le azioni della redazione su locali ed eventi, chiamate come le
 * chiama il pannello. Ogni azione passa dalle Policy: la prova in negativo
 * (un moderatore che non può, un utente che non deve) vale quanto quella in
 * positivo.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-10 10:00:00', 'UTC'));

    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
    $this->actingAs($this->admin);

    $this->city = City::factory()->padova()->create();
    $this->category = Category::factory()->create(['default_duration_minutes' => 180]);
    $this->venue = Venue::factory()->create([
        'city_id' => $this->city->getKey(),
        'status' => VenueStatus::Pending,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function editVenue(Venue $venue): Testable
{
    return Livewire::test(EditVenue::class, ['record' => $venue->getRouteKey()]);
}

it('approva un locale registrando chi ha deciso e quando', function (): void {
    editVenue($this->venue)->callAction('approve');

    $this->venue->refresh();

    expect($this->venue->status)->toBe(VenueStatus::Approved)
        ->and($this->venue->approved_by)->toBe($this->admin->getKey())
        ->and($this->venue->approved_at)->not->toBeNull();
});

it('rifiuta un locale chiedendo il motivo e lo conserva', function (): void {
    editVenue($this->venue)->callAction('reject', ['reason' => 'Indirizzo inesistente']);

    $this->venue->refresh();

    expect($this->venue->status)->toBe(VenueStatus::Rejected)
        ->and($this->venue->rejection_reason)->toBe('Indirizzo inesistente')
        ->and($this->venue->approved_at)->toBeNull();
});

it('sospende un locale approvato senza cancellarne l\'approvazione', function (): void {
    $approved = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    editVenue($approved)->callAction('suspend', ['reason' => 'Segnalazioni ripetute']);

    $approved->refresh();

    expect($approved->status)->toBe(VenueStatus::Suspended)
        ->and($approved->approved_at)->not->toBeNull();
});

it('registra ogni decisione sul locale nella cronologia', function (): void {
    editVenue($this->venue)->callAction('approve');

    $logged = Activity::query()
        ->where('subject_type', 'venue')
        ->where('subject_id', $this->venue->getKey())
        ->count();

    expect($logged)->toBeGreaterThan(0);
});

it('nega le azioni di moderazione a chi non ha il permesso', function (): void {
    $plain = User::factory()->create();
    $plain->assignRole(UserRole::User->value);

    expect($plain->can('moderate', $this->venue))->toBeFalse();

    // Il moderatore invece ce l'ha: è il suo mestiere.
    $moderator = User::factory()->create();
    $moderator->assignRole(UserRole::Moderator->value);

    expect($moderator->can('moderate', $this->venue))->toBeTrue();
});

it('duplica un evento come bozza, senza le sue date', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);
    $tag = Tag::factory()->create();

    $event = Event::factory()->create([
        'city_id' => $this->city->getKey(),
        'category_id' => $this->category->getKey(),
        'venue_id' => $venue->getKey(),
        'title' => 'Rassegna di teatro civile',
        'status' => EventStatus::Published,
        'published_at' => now(),
        'source_ref' => 'ics-123',
        'views_count' => 40,
    ]);
    $event->tags()->attach($tag);

    EventOccurrence::factory()->create(['event_id' => $event->getKey(), 'ends_at' => null]);

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])->callAction('duplicate');

    $copy = Event::query()->where('id', '!=', $event->getKey())->firstOrFail();

    expect($copy->status)->toBe(EventStatus::Draft)
        ->and($copy->published_at)->toBeNull()
        ->and($copy->source_ref)->toBeNull()
        ->and($copy->views_count)->toBe(0)
        ->and($copy->slug)->not->toBe($event->slug)
        ->and($copy->occurrences()->count())->toBe(0)
        ->and($copy->tags->pluck('id')->all())->toBe([$tag->getKey()]);
});

it('annulla un evento e con esso le date future, non quelle passate', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    $event = Event::factory()->create([
        'city_id' => $this->city->getKey(),
        'category_id' => $this->category->getKey(),
        'venue_id' => $venue->getKey(),
        'status' => EventStatus::Published,
    ]);

    $past = EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => CarbonImmutable::parse('2026-08-01 21:00:00', 'UTC'),
        'ends_at' => null,
    ]);

    $future = EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => CarbonImmutable::parse('2026-10-01 21:00:00', 'UTC'),
        'ends_at' => null,
    ]);

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('cancel_event', ['note' => 'Maltempo']);

    expect($event->refresh()->status)->toBe(EventStatus::Cancelled)
        ->and($past->refresh()->status)->toBe(OccurrenceStatus::Scheduled)
        ->and($future->refresh()->status)->toBe(OccurrenceStatus::Cancelled)
        ->and($future->status_note)->toBe('Maltempo');
});

it('genera le date di una ricorrenza dal pannello, e non le raddoppia', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    $event = Event::factory()->create([
        'city_id' => $this->city->getKey(),
        'category_id' => $this->category->getKey(),
        'venue_id' => $venue->getKey(),
        'status' => EventStatus::Draft,
    ]);

    EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => CarbonImmutable::parse('2026-09-17 19:00:00', 'Europe/Rome')->utc(),
        'ends_at' => null,
        'recurrence_id' => null,
    ]);

    EventRecurrence::factory()->create([
        'event_id' => $event->getKey(),
        'rrule' => 'FREQ=WEEKLY;BYDAY=TH;COUNT=4',
        'until' => null,
        'exdates' => null,
    ]);

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('generate_occurrences');

    $afterFirstRun = $event->occurrences()->count();

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('generate_occurrences');

    expect($afterFirstRun)->toBe(4)
        ->and($event->occurrences()->count())->toBe(4);
});

it('mette e toglie l\'evidenza', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey()]);

    $event = Event::factory()->create([
        'city_id' => $this->city->getKey(),
        'category_id' => $this->category->getKey(),
        'venue_id' => $venue->getKey(),
        'is_featured' => false,
        'featured_until' => null,
    ]);

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('feature', ['featured_until' => '2026-10-01 00:00:00']);

    expect($event->refresh()->is_featured)->toBeTrue()
        ->and($event->featured_until)->not->toBeNull();

    Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('feature');

    expect($event->refresh()->is_featured)->toBeFalse()
        ->and($event->featured_until)->toBeNull();
});
