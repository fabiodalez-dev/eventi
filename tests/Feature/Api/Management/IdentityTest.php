<?php

declare(strict_types=1);

use App\DTOs\ExternalLinkList;
use App\DTOs\FactList;
use App\Enums\VenueRole;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Models\Venue;
use App\Services\Management\ManagementOptions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\VenueIsolationScenario;

/*
 * L'identità di gestione: `GET /management/subjects`, `GET /management/options`,
 * i conteggi `management` di `GET /me` e l'interruttore `features.management`.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
});

it('elenca i locali con la forma del contratto', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $this->getJson('/api/v1/management/subjects')->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertJsonCount(1, 'data.venues')
        ->assertJsonCount(0, 'data.organizers')
        ->assertJsonPath('data.checkin_only', false)
        ->assertJsonPath('data.venues.0.id', $this->s->venueA->id)
        ->assertJsonPath('data.venues.0.slug', $this->s->venueA->slug)
        ->assertJsonPath('data.venues.0.name', 'Circolo Aurora')
        ->assertJsonPath('data.venues.0.city.name', $this->s->city->name)
        ->assertJsonPath('data.venues.0.city.timezone', 'Europe/Rome')
        ->assertJsonPath('data.venues.0.role', 'owner')
        ->assertJsonPath('data.venues.0.status', 'approved')
        ->assertJsonPath('data.venues.0.logo', null)
        ->assertJsonStructure(['data' => ['venues' => [['id', 'slug', 'name', 'city' => ['name', 'timezone'], 'role', 'status', 'logo', 'ticketing_enabled', 'auto_publish', 'permissions']]]]);
});

it('riconosce chi fa solo il controllo ingressi e lo conta in /me', function (): void {
    $staff = User::factory()->create();
    $next = EventOccurrence::factory()->create(['event_id' => $this->s->publishedEventA->id, 'starts_at' => CarbonImmutable::now()->addDay(), 'ends_at' => null]);
    $next->checkinStaff()->attach($staff);
    // Una data già finita non conta.
    $this->s->occurrenceA->update(['starts_at' => CarbonImmutable::now()->subMonth()]);
    $this->s->occurrenceA->checkinStaff()->attach($staff);

    Sanctum::actingAs($staff);
    $this->getJson('/api/v1/management/subjects')->assertOk()->assertJsonPath('data.checkin_only', true);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.management', ['venues' => 0, 'organizers' => 0, 'checkin' => 1]);

    Sanctum::actingAs($this->s->plainUser);
    $this->getJson('/api/v1/management/subjects')->assertOk()->assertJsonPath('data.checkin_only', false);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.management', ['venues' => 0, 'organizers' => 0, 'checkin' => 0]);
});

it('conta in /me solo i locali senza provvedimento, e non offre la biglietteria a chi ha solo un locale sospeso', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.management.venues', 1);

    $owner = User::factory()->create();
    $suspended = Venue::factory()->suspended()->create(['city_id' => $this->s->city->id]);
    $owner->venues()->attach($suspended, ['role' => VenueRole::Owner->value, 'invited_at' => now(), 'accepted_at' => now()]);

    Sanctum::actingAs($owner);
    $me = $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.management.venues', 0)->json('data');

    expect(collect($me['management_links'])->pluck('icon')->all())->not->toContain('tickets', 'venue');
});

it('pubblica gli elenchi e i tetti dei moduli', function (): void {
    Sanctum::actingAs($this->s->editorA);

    $data = $this->getJson('/api/v1/management/options')->assertOk()->json('data');

    expect($data)->toHaveKeys(['price_types', 'occurrence_statuses', 'recurrence_frequencies', 'lineup_roles', 'accessibility_features', 'transit_modes',
        'age_groups', 'membership_requirements', 'practical_icons', 'event_features', 'booking_question_types', 'social_formats', 'sponsorship_placements', 'limits', 'images'])
        ->and(collect($data['price_types'])->pluck('value')->all())->toContain('free', 'ticket')
        ->and(collect($data['occurrence_statuses']['venue'])->pluck('value')->all())->toBe(['scheduled', 'sold_out', 'cancelled'])
        ->and(collect($data['occurrence_statuses']['organizer'])->pluck('value')->all())->toContain('postponed', 'moved')
        ->and($data['limits'])->toMatchArray([
            'external_links' => ExternalLinkList::MAX_LINKS,
            'facts' => FactList::MAX_FACTS,
            'venue_closures' => 20,
            'venue_notice_min_days_between' => 7,
            'attendee_messages_per_day' => 3,
            'gallery_images' => ManagementOptions::GALLERY_MAX,
        ])
        ->and($data['images'])->toMatchArray(['max_upload_bytes' => 12 * 1024 * 1024, 'min_width' => 200, 'min_height' => 200])
        ->and($data['images']['accepted_mime_types'])->toContain('image/jpeg', 'image/heic')
        ->and($data['booking_question_types'][0])->toBe(['value' => 'text', 'label' => 'Testo libero']);
});

it('accende l’area nuova dell’app da /config', function (): void {
    $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('data.features.management', true);
});
