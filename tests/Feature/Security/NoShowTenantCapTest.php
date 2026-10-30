<?php

declare(strict_types=1);

use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/*
 * D64: le assenze che bloccano le prenotazioni gratuite su tutta la
 * piattaforma non possono venire tutte dallo stesso gestore. Ai fini del
 * blocco globale ogni «tenant» (il locale della data, o l'organizzatore se la
 * data non ha locale) conta al massimo limite - 1 assenze; presso lo stesso
 * tenant contano tutte.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));
    (new RolesAndPermissionsSeeder)->run();
    config()->set('ticketing.no_show.limit', 3);
    config()->set('ticketing.no_show.window_days', 90);
    $this->service = app(TicketingService::class);
    $this->user = User::factory()->create();
    $this->venueA = Venue::factory()->approved()->create(['city_id' => testCity()->getKey(), 'ticketing_enabled' => true]);
    $this->venueB = Venue::factory()->approved()->create(['city_id' => testCity()->getKey(), 'ticketing_enabled' => true]);
    $this->venueC = Venue::factory()->approved()->create(['city_id' => testCity()->getKey(), 'ticketing_enabled' => true]);
    // Una data passata (dentro la finestra) e una futura prenotabile, per locale.
    $this->past = fn (Venue $venue, array $event = []): EventOccurrence => occurrenceAt(testCity(), testCategory(), '2026-10-10 18:00:00', '2026-10-10 22:00:00', ['booking_enabled' => true], $event, $venue);
    $this->future = fn (Venue $venue): EventOccurrence => occurrenceAt(testCity(), testCategory(), '2026-10-30 18:00:00', '2026-10-30 22:00:00', ['booking_enabled' => true, 'booking_capacity' => 10], [], $venue);
    /* Assenze scritte direttamente: il comando che le segna ha i suoi test,
       qui conta solo come vengono contate. */
    $this->absent = function (EventOccurrence $date, int $count, ?User $user = null): void {
        $booking = Booking::query()->create([
            'occurrence_id' => $date->id, 'user_id' => ($user ?? $this->user)->id, 'request_key' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64), 'status' => BookingStatus::Confirmed,
        ]);
        foreach (range(1, $count) as $i) {
            $booking->tickets()->create(['attendee_name' => 'Persona '.$i, 'code' => Str::random(64), 'status' => AdmissionStatus::NoShow]);
        }
    };
});

it('non blocca su tutta la piattaforma per tre assenze segnate da un solo locale', function (): void {
    ($this->absent)(($this->past)($this->venueA), 3);

    expect($this->service->noShowCount($this->user))->toBe(3)
        ->and($this->service->globalNoShowCount($this->user))->toBe(2);

    $booking = $this->service->reserve($this->user, ($this->future)($this->venueB), ['Ada'], (string) Str::uuid(), false);
    expect($booking->status)->toBe(BookingStatus::Confirmed);
});

it('lascia allo stesso locale il blocco delle sue date, con il messaggio del locale', function (): void {
    ($this->absent)(($this->past)($this->venueA), 2);
    ($this->absent)(($this->past)($this->venueA), 1);
    $again = ($this->future)($this->venueA);

    expect(fn () => $this->service->reserve($this->user, $again, ['Ada'], (string) Str::uuid(), false))
        ->toThrow(ValidationException::class, __('ticketing.errors.no_show_limit_venue'));
    expect(__('ticketing.errors.no_show_limit_venue'))->not->toBe(__('ticketing.errors.no_show_limit'));

    // Anche dall'app: il motivo arriva nel campo `ticketing` dell'errore.
    Sanctum::actingAs($this->user);
    $this->postJson('/api/v1/occurrences/'.$again->id.'/bookings', ['attendees' => ['Ada Rossi'], 'request_key' => (string) Str::uuid(), 'accept_terms' => true])
        ->assertUnprocessable()->assertJsonPath('error.fields.ticketing.0', __('ticketing.errors.no_show_limit_venue'));
    expect(Booking::query()->where('occurrence_id', $again->id)->exists())->toBeFalse();
});

it('blocca ovunque quando le assenze vengono da almeno due locali', function (): void {
    ($this->absent)(($this->past)($this->venueA), 2);
    ($this->absent)(($this->past)($this->venueB), 1);

    expect($this->service->globalNoShowCount($this->user))->toBe(3);
    expect(fn () => $this->service->reserve($this->user, ($this->future)($this->venueC), ['Ada'], (string) Str::uuid(), false))
        ->toThrow(ValidationException::class, __('ticketing.errors.no_show_limit'));
});

it('conta al massimo limite meno uno anche quando un locale ne ha segnate molte', function (): void {
    ($this->absent)(($this->past)($this->venueA), 6);
    ($this->absent)(($this->past)($this->venueB), 1);
    // A: 6 → 2, B: 1 → 1. Siamo a 3: blocco globale.
    expect($this->service->globalNoShowCount($this->user))->toBe(3);

    $other = User::factory()->create();
    ($this->absent)(($this->past)($this->venueA), 6, $other);
    expect($this->service->globalNoShowCount($other))->toBe(2);
    $this->service->reserve($other, ($this->future)($this->venueC), ['Bea'], (string) Str::uuid(), false);
});

it('raggruppa per organizzatore le date senza locale', function (): void {
    $organizer = Organizer::create(['name' => 'Collettivo Aurora', 'city_id' => testCity()->getKey(), 'owner_id' => User::factory()->create()->id, 'is_active' => true]);
    // Due eventi diversi dello stesso organizzatore, senza locale: un solo tenant.
    $first = occurrenceAt(testCity(), testCategory(), '2026-10-10 18:00:00', '2026-10-10 22:00:00', [], ['organizer_id' => $organizer->id]);
    $first->event->update(['venue_id' => null]);
    $second = occurrenceAt(testCity(), testCategory(), '2026-10-12 18:00:00', '2026-10-12 22:00:00', [], ['organizer_id' => $organizer->id]);
    $second->event->update(['venue_id' => null]);
    ($this->absent)($first->fresh(), 2);
    ($this->absent)($second->fresh(), 2);

    expect($this->service->noShowCount($this->user))->toBe(4)
        ->and($this->service->globalNoShowCount($this->user))->toBe(2);
    $this->service->reserve($this->user, ($this->future)($this->venueB), ['Ada'], (string) Str::uuid(), false);

    /* Il locale della singola data prevale su quello dell'evento: le due
       assenze della data spostata in C non si sommano alle due di A. */
    ($this->absent)(($this->past)($this->venueA), 2);
    $moved = occurrenceAt(testCity(), testCategory(), '2026-10-14 18:00:00', '2026-10-14 22:00:00', [], [], $this->venueA);
    EventOccurrence::query()->whereKey($moved->id)->update(['venue_id' => $this->venueC->id]);
    ($this->absent)($moved, 2);
    expect($this->service->globalNoShowCount($this->user))->toBe(6);
});

it('non lascia bloccare ovunque a un solo organizzatore con serate in due locali', function (): void {
    $organizer = Organizer::create(['name' => 'Rassegna Portici', 'city_id' => testCity()->getKey(), 'owner_id' => User::factory()->create()->id, 'is_active' => true]);
    // L'organizzatore gestisce gli ingressi di entrambe le serate: è un solo gestore.
    ($this->absent)(($this->past)($this->venueA, ['organizer_id' => $organizer->id]), 2);
    ($this->absent)(($this->past)($this->venueB, ['organizer_id' => $organizer->id]), 1);

    expect($this->service->globalNoShowCount($this->user))->toBe(2);
    $this->service->reserve($this->user, ($this->future)($this->venueC), ['Ada'], (string) Str::uuid(), false);

    // Un'assenza vista da un altro gestore lo porta al limite.
    ($this->absent)(($this->past)($this->venueC), 1);
    expect($this->service->globalNoShowCount($this->user))->toBe(3);
});

it('ignora ancora le assenze fuori dalla finestra di novanta giorni', function (): void {
    ($this->absent)(occurrenceAt(testCity(), testCategory(), '2026-06-01 18:00:00', '2026-06-01 22:00:00', [], [], $this->venueA), 2);
    ($this->absent)(($this->past)($this->venueB), 2);

    expect($this->service->globalNoShowCount($this->user))->toBe(2);
    $this->service->reserve($this->user, ($this->future)($this->venueC), ['Ada'], (string) Str::uuid(), false);
});
