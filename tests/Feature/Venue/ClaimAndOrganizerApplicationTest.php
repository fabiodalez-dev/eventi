<?php

declare(strict_types=1);

use App\Actions\ApproveVenueApplication;
use App\Enums\ApplicationKind;
use App\Enums\ApplicationStatus;
use App\Http\Requests\Web\StoreVenueApplicationRequest;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueApplication;
use App\Notifications\OrganizerAccessGranted;
use App\Notifications\VenueAccessGranted;
use App\Notifications\VenueApplicationApproved;
use App\Services\Geo\AddressGeocoder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

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
    $marta = User::factory()->create(['email' => 'marta@example.test', 'name' => 'Marta Gestore']);
    $this->get(route('venues.show', $venue))->assertOk()->assertSee(__('venues.claim_card.action'));
    $this->actingAs($marta)->get(route('venues.claim.create', $venue))->assertOk()->assertSee('Osteria Importata')->assertSee('marta@example.test');
    $this->actingAs($marta)->post(route('venues.claim.store', $venue), [
        'venue_name' => 'ignorato',
        'contact_name' => 'Marta Gestore',
        // Da collegata conta l'email dell'account, non quella scritta nel modulo.
        'contact_email' => 'qualcun-altro@example.test',
        'message' => 'Sono la titolare dal 2019.',
        StoreVenueApplicationRequest::STARTED_FIELD => StoreVenueApplicationRequest::startedToken(now()->subMinute()),
    ])->assertRedirect(route('venues.claim.create', $venue))->assertSessionHas('status');
    $claim = VenueApplication::query()->sole();
    expect($claim->kind())->toBe(ApplicationKind::Claim)
        ->and($claim->venue_id)->toBe($venue->id)
        ->and($claim->venue_name)->toBe('Osteria Importata')
        ->and($claim->user_id)->toBe($marta->id)
        ->and($claim->contact_email)->toBe('marta@example.test')
        ->and($claim->isAwaitingEmail())->toBeFalse();

    $result = app(ApproveVenueApplication::class)->handle($claim, $this->moderator);
    expect($result->is($venue))->toBeTrue()
        ->and(Venue::query()->count())->toBe(1)
        // Rivendicazione dal suo account confermato: Marta entra subito, senza collegamenti a scadenza.
        ->and($venue->fresh()->owners()->where('email', 'marta@example.test')->exists())->toBeTrue()
        ->and($claim->fresh()->status)->toBe(ApplicationStatus::Approved);
    Notification::assertSentTo($marta, VenueApplicationApproved::class);
    Notification::assertNotSentTo($marta, VenueAccessGranted::class);

    // A competing pending claim is closed, and approving it afterwards is refused.
    $rival = VenueApplication::factory()->create(['kind' => ApplicationKind::Claim, 'venue_id' => $venue->id, 'venue_name' => $venue->name]);
    expect($rival->fresh()->status)->toBe(ApplicationStatus::Pending);
    $rival->update(['status' => ApplicationStatus::Pending]);
    expect(fn () => app(ApproveVenueApplication::class)->handle($rival, $this->moderator))->toThrow(ValidationException::class);
    $third = VenueApplication::factory()->create(['kind' => ApplicationKind::Claim, 'venue_id' => $venue->id, 'venue_name' => $venue->name]);
    $fresh = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $claimA = VenueApplication::factory()->create(['kind' => ApplicationKind::Claim, 'venue_id' => $fresh->id, 'venue_name' => $fresh->name]);
    $claimB = VenueApplication::factory()->create(['kind' => ApplicationKind::Claim, 'venue_id' => $fresh->id, 'venue_name' => $fresh->name]);
    app(ApproveVenueApplication::class)->handle($claimA, $this->moderator);
    expect($claimB->fresh()->status)->toBe(ApplicationStatus::Rejected)->and($claimB->fresh()->notes)->toBe(__('venue_applications.claim_superseded'));
    unset($third);
    // Once managed, the venue cannot be claimed again.
    $this->get(route('venues.claim.create', $venue))->assertNotFound();
    $this->get(route('venues.show', $venue))->assertOk()->assertDontSee(__('venues.claim_card.action'));
    $draft = Venue::factory()->create(['city_id' => $this->city->id, 'status' => 'draft']);
    $this->get(route('venues.claim.create', $draft))->assertNotFound();
});

it('registers an organizer without a venue and approving creates it active with the applicant as owner', function (): void {
    $this->get(route('organizer-applications.create'))->assertOk()->assertSee(__('forms.organizer_application.title'));
    $this->get('/')->assertOk()->assertSee(__('forms.organizer_application.title'));
    $this->post(route('organizer-applications.store'), ['venue_name' => 'Collettivo Portici', 'website' => 'https://portici.example', 'contact_name' => 'Luca Rossi', 'contact_email' => 'luca@example.test', 'message' => 'Rassegna di concerti itineranti.', StoreVenueApplicationRequest::STARTED_FIELD => StoreVenueApplicationRequest::startedToken(now()->subMinute())])
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
