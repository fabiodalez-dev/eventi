<?php

declare(strict_types=1);

use App\Actions\ApproveVenueApplication;
use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Enums\VenueType;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Services\Geo\AddressGeocoder;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/**
 * Correzioni dell'audit di novembre su personale di ingresso, blocco per
 * assenze e candidature degli organizzatori.
 */
beforeEach(function (): void {
    Notification::fake();
    (new RolesAndPermissionsSeeder)->run();
});

it('non rivela a chi gestisce una data se un indirizzo è registrato né il nome dell\'account', function (): void {
    $this->travelTo(now()->setDate(2026, 11, 13)->setTime(12, 0));
    $city = testCity();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'ticketing_enabled' => true]);
    $date = occurrenceAt($city, testCategory(), '2026-11-20 18:00:00', '2026-11-20 22:00:00', ['booking_enabled' => true], ['organizer_id' => null], $venue);
    $owner = User::factory()->create();
    $venue->members()->attach($owner, ['role' => 'owner', 'accepted_at' => now()]);
    User::factory()->create(['name' => 'Nome Riservato', 'email' => 'registrato@example.test']);

    Sanctum::actingAs($owner, ['*']);
    $unknown = $this->postJson('/api/v1/management/dates/'.$date->id.'/staff', ['email' => 'sconosciuto@example.test']);
    $known = $this->postJson('/api/v1/management/dates/'.$date->id.'/staff', ['email' => 'registrato@example.test']);

    expect($unknown->status())->toBe($known->status())->toBe(200);
    $staff = $this->getJson('/api/v1/management/dates/'.$date->id)->json('data.staff');
    expect(json_encode($staff))->not->toContain('Nome Riservato');
});

it('non lascia a chi possiede due locali chiudere da solo le prenotazioni di una persona altrove', function (): void {
    config()->set('ticketing.no_show.limit', 3);
    config()->set('ticketing.no_show.min_checked_in_share', 0.25);
    $service = app(TicketingService::class);
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(12, 0));
    $city = testCity();
    $category = testCategory();
    $owner = User::factory()->create();
    $decoy = User::factory()->create();
    $victim = User::factory()->create();
    [$venueA, $venueB, $venueC] = Venue::factory()->approved()->count(3)->create(['city_id' => $city->getKey(), 'ticketing_enabled' => true])->all();
    $venueA->members()->attach($owner, ['role' => 'owner', 'accepted_at' => now()]);
    $venueB->members()->attach($owner, ['role' => 'owner', 'accepted_at' => now()]);
    $dateA = occurrenceAt($city, $category, '2026-11-02 18:00:00', '2026-11-02 22:00:00', ['booking_enabled' => true, 'booking_capacity' => 20], ['organizer_id' => null], $venueA);
    $dateB = occurrenceAt($city, $category, '2026-11-03 18:00:00', '2026-11-03 22:00:00', ['booking_enabled' => true, 'booking_capacity' => 20], ['organizer_id' => null], $venueB);
    $service->reserve($victim, $dateA, ['Vittima', 'Amica'], (string) Str::uuid(), false);
    $service->reserve($victim, $dateB, ['Vittima'], (string) Str::uuid(), false);
    $decoyA = $service->reserve($decoy, $dateA, ['Esca'], (string) Str::uuid(), false);
    $decoyB = $service->reserve($decoy, $dateB, ['Esca'], (string) Str::uuid(), false);
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(19, 0));
    $service->checkIn($dateA, $decoyA->tickets->first()->code, $owner);
    $this->travelTo(now()->setDate(2026, 11, 3)->setTime(19, 0));
    $service->checkIn($dateB, $decoyB->tickets->first()->code, $owner);
    $this->travelTo(now()->setDate(2026, 11, 4)->setTime(6, 0));
    expect($service->markNoShows())->toBe(3);

    expect($service->globalNoShowCount($victim))->toBe(2);
    $future = occurrenceAt($city, $category, '2026-11-20 18:00:00', '2026-11-20 22:00:00', ['booking_enabled' => true, 'booking_capacity' => 10], ['organizer_id' => null], $venueC);
    $service->reserve($victim, $future, ['Vittima'], (string) Str::uuid(), false);
    expect($future->bookings()->where('user_id', $victim->id)->exists())->toBeTrue();
});

it('non aggancia a un organizzatore l\'account indicato nell\'email libera se non ha mandato lui la richiesta', function (): void {
    $geocoder = Mockery::mock(AddressGeocoder::class);
    $geocoder->shouldReceive('coordinate')->andReturn(null)->byDefault();
    app()->instance(AddressGeocoder::class, $geocoder);
    testCity();
    $victim = User::factory()->create(['email' => 'vittima@example.test', 'email_verified_at' => now()]);
    $moderator = User::factory()->create();
    $application = VenueApplication::query()->create([
        'kind' => ApplicationKind::Organizer, 'user_id' => null, 'venue_name' => 'Collettivo Inventato',
        'contact_name' => 'Chiunque', 'contact_email' => 'vittima@example.test', 'message' => 'x',
        'status' => ApplicationStatus::Pending, 'type' => VenueType::Altro,
    ]);

    expect(fn () => app(ApproveVenueApplication::class)->handle($application, $moderator))->toThrow(ValidationException::class);
    expect(Organizer::query()->count())->toBe(0)
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Pending);
});

it('aggancia subito l\'account che ha mandato lui la richiesta, da collegato e con la casella confermata', function (): void {
    $geocoder = Mockery::mock(AddressGeocoder::class);
    $geocoder->shouldReceive('coordinate')->andReturn(null)->byDefault();
    app()->instance(AddressGeocoder::class, $geocoder);
    testCity();
    $requester = User::factory()->create(['email' => 'referente@example.test', 'email_verified_at' => now()]);
    $application = VenueApplication::query()->create([
        'kind' => ApplicationKind::Organizer, 'user_id' => $requester->id, 'venue_name' => 'Collettivo Vero',
        'contact_name' => 'Referente', 'contact_email' => 'referente@example.test', 'message' => 'x',
        'status' => ApplicationStatus::Pending, 'type' => VenueType::Altro,
    ]);

    $organizer = app(ApproveVenueApplication::class)->handle($application, User::factory()->create());

    expect($organizer)->toBeInstanceOf(Organizer::class)
        ->and($organizer->managedBy($requester))->toBeTrue();
});
