<?php

declare(strict_types=1);

use App\Actions\ApproveVenueApplication;
use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Notifications\OrganizerAccessGranted;
use App\Notifications\VenueAccessGranted;
use App\Services\Geo\AddressGeocoder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $geocoder = Mockery::mock(AddressGeocoder::class);
    $geocoder->shouldReceive('coordinate')->andReturn(['lat' => 45.4111, 'lng' => 11.8765])->byDefault();
    app()->instance(AddressGeocoder::class, $geocoder);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->city = testCity();
    $this->moderator = User::factory()->create();
});

it('lets a public venue without a referent be claimed, and approving hands over the keys instead of creating a twin', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Osteria Importata']);
    $this->get(route('venues.show', $venue))->assertOk()->assertSee(__('forms.claim.cta'));
    $this->get(route('venues.claim.create', $venue))->assertOk()->assertSee('Osteria Importata');
    $this->post(route('venues.claim.store', $venue), ['venue_name' => 'ignorato', 'contact_name' => 'Marta Gestore', 'contact_email' => 'marta@example.test', 'message' => 'Sono la titolare dal 2019.'])
        ->assertRedirect(route('venues.claim.create', $venue))->assertSessionHas('status');
    $claim = VenueApplication::query()->sole();
    expect($claim->kind())->toBe(ApplicationKind::Claim)
        ->and($claim->venue_id)->toBe($venue->id)
        ->and($claim->venue_name)->toBe('Osteria Importata');

    $result = app(ApproveVenueApplication::class)->handle($claim, $this->moderator);
    expect($result->is($venue))->toBeTrue()
        ->and(Venue::query()->count())->toBe(1)
        ->and($venue->fresh()->owners()->where('email', 'marta@example.test')->exists())->toBeTrue()
        ->and($claim->fresh()->status)->toBe(ApplicationStatus::Approved);
    Notification::assertSentTo(User::where('email', 'marta@example.test')->firstOrFail(), VenueAccessGranted::class);

    // Once managed, the venue cannot be claimed again.
    $this->get(route('venues.claim.create', $venue))->assertNotFound();
    $this->get(route('venues.show', $venue))->assertOk()->assertDontSee(__('forms.claim.cta'));
    $draft = Venue::factory()->create(['city_id' => $this->city->id, 'status' => 'draft']);
    $this->get(route('venues.claim.create', $draft))->assertNotFound();
});

it('registers an organizer without a venue and approving creates it active with the applicant as owner', function (): void {
    $this->get(route('organizer-applications.create'))->assertOk()->assertSee(__('forms.organizer_application.title'));
    $this->get('/')->assertOk()->assertSee(__('forms.organizer_application.title'));
    $this->post(route('organizer-applications.store'), ['venue_name' => 'Collettivo Portici', 'website' => 'https://portici.example', 'contact_name' => 'Luca Rossi', 'contact_email' => 'luca@example.test', 'message' => 'Rassegna di concerti itineranti.'])
        ->assertRedirect(route('organizer-applications.create'))->assertSessionHas('status');
    $application = VenueApplication::query()->sole();
    expect($application->kind())->toBe(ApplicationKind::Organizer)->and($application->socials['website'])->toBe('https://portici.example');

    $organizer = app(ApproveVenueApplication::class)->handle($application, $this->moderator);
    expect($organizer)->toBeInstanceOf(Organizer::class)
        ->and($organizer->is_active)->toBeTrue()
        ->and($organizer->website)->toBe('https://portici.example')
        ->and($organizer->slug)->toBe('collettivo-portici')
        ->and($organizer->owner?->email)->toBe('luca@example.test')
        ->and($organizer->owner->canAccessPanel(Filament::getPanel('organizer')))->toBeTrue()
        ->and(Venue::query()->count())->toBe(0)
        ->and($application->fresh()->organizer_id)->toBe($organizer->id);
    Notification::assertSentTo($organizer->owner, OrganizerAccessGranted::class);
    // Approving twice does not create a second organizer.
    app(ApproveVenueApplication::class)->handle($application->fresh(), $this->moderator);
    expect(Organizer::query()->count())->toBe(1);
});
