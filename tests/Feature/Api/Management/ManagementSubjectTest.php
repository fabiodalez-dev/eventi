<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Enums\VenueRole;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\User;
use App\Models\Venue;
use App\Services\Analytics\EventShares;
use App\Services\Analytics\ManagementAnalytics;
use App\Services\Management\ManagementPermissions;
use App\Services\Sponsorship\SponsorshipReport;
use App\Support\Management\ManagementSubject;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ImageFixtures;
use Tests\Support\VenueIsolationScenario;

/*
 * La porta delle rotte di gestione (`ResolveManagementSubject`) e i permessi
 * per area di `GET /management/subjects`: chi non appartiene al soggetto
 * riceve 404, il locale sospeso chiude le scritture, referente e
 * collaboratore (proprietario e membro dell'organizzazione) vedono permessi
 * diversi, calcolati dalle stesse policy dei pannelli.
 */
beforeEach(function (): void {
    Notification::fake();
    Storage::fake(config()->string('media-library.disk_name'));
    $this->s = VenueIsolationScenario::make();
});

function managementPoster(): array
{
    return ['image' => ImageFixtures::upload('locandina.png', ImageFixtures::png(400, 500))];
}

it('chiede il token prima di tutto', function (): void {
    $this->getJson('/api/v1/management/subjects')->assertUnauthorized();
    $this->getJson('/api/v1/management/venues/'.$this->s->venueA->id.'/media')->assertUnauthorized();
});

it('risponde 404 a chi non appartiene al soggetto, senza dire se esiste', function (): void {
    $url = '/api/v1/management/venues/'.$this->s->venueA->id.'/media';

    foreach ([$this->s->ownerB, $this->s->plainUser, $this->s->admin, $this->s->moderator] as $stranger) {
        Sanctum::actingAs($stranger);
        $this->getJson($url)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    Sanctum::actingAs($this->s->ownerA);
    $this->getJson($url)->assertOk();
    $this->getJson('/api/v1/management/venues/999999/media')->assertNotFound();
    $this->postJson('/api/v1/management/organizers/999999/events/'.$this->s->draftEventA->id.'/poster', managementPoster())->assertNotFound();
});

it('non apre un evento di un altro locale nemmeno passando dal proprio', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $this->postJson('/api/v1/management/venues/'.$this->s->venueA->id.'/events/'.$this->s->draftEventB->id.'/poster', managementPoster())
        ->assertNotFound();
    $this->postJson('/api/v1/management/venues/'.$this->s->venueB->id.'/events/'.$this->s->draftEventB->id.'/poster', managementPoster())
        ->assertNotFound();

    expect($this->s->draftEventB->fresh()->getMedia('poster'))->toHaveCount(0);
});

it('chiude le rotte nuove al membro di un locale sospeso, che le sole policy lascerebbero passare', function (): void {
    $venue = Venue::factory()->suspended()->create(['city_id' => $this->s->city->id]);
    $editor = User::factory()->create();
    $editor->assignRole(UserRole::VenueEditor->value);
    $editor->venues()->attach($venue, ['role' => VenueRole::Editor->value, 'invited_at' => CarbonImmutable::now(), 'accepted_at' => CarbonImmutable::now()]);
    $event = Event::factory()->create(['city_id' => $this->s->city->id, 'category_id' => $this->s->category->id, 'venue_id' => $venue->id, 'status' => EventStatus::Draft]);

    // È il buco che la porta chiude: la policy guarda l'appartenenza, non lo stato del locale.
    expect($editor->can('update', $event))->toBeTrue();

    Sanctum::actingAs($editor);
    $this->postJson('/api/v1/management/venues/'.$venue->id.'/events/'.$event->id.'/poster', managementPoster())->assertNotFound();
    $this->deleteJson('/api/v1/management/venues/'.$venue->id.'/events/'.$event->id.'/poster')->assertNotFound();
    $this->getJson('/api/v1/management/subjects')->assertOk()->assertJsonCount(0, 'data.venues');

    expect($event->fresh()->getMedia('poster'))->toHaveCount(0);
});

it('dà al referente e al collaboratore del locale permessi diversi, dalle policy del pannello', function (): void {
    $this->s->venueA->update(['auto_publish' => true]);

    Sanctum::actingAs($this->s->ownerA);
    $owner = $this->getJson('/api/v1/management/subjects')->assertOk()->json('data.venues.0');
    Sanctum::actingAs($this->s->editorA);
    $editor = $this->getJson('/api/v1/management/subjects')->assertOk()->json('data.venues.0');

    expect(array_keys($owner['permissions']))->toBe(ManagementPermissions::KEYS)
        ->and($owner['role'])->toBe('owner')
        ->and($editor['role'])->toBe('editor')
        ->and($owner['permissions'])->toMatchArray([
            'events_create' => true, 'events_publish_directly' => true, 'ticketing_manage' => true, 'profile_update' => true,
            'collaborators_manage' => true, 'import_manage' => true, 'analytics_channels' => true, 'sponsorships_purchase' => true,
            // L'unico referente non può uscire dal locale.
            'leave' => false,
        ])
        ->and($editor['permissions'])->toMatchArray([
            'events_create' => true, 'events_publish_directly' => true, 'ticketing_manage' => false, 'profile_update' => false,
            'collaborators_manage' => false, 'import_manage' => false, 'analytics_channels' => false, 'sponsorships_purchase' => false,
            'analytics_view' => true, 'sponsorships_view' => true, 'social_use' => true, 'comments_moderate' => true, 'reviews_reply' => true,
            'leave' => true,
        ]);

    // Senza pubblicazione autonoma nessuno dei due pubblica da sé.
    $this->s->venueA->update(['auto_publish' => false]);
    Sanctum::actingAs($this->s->ownerA);
    $this->getJson('/api/v1/management/subjects')->assertJsonPath('data.venues.0.permissions.events_publish_directly', false);
});

it('distingue il proprietario e il membro dell’organizzazione', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->s->city->id, 'owner_id' => $owner->id, 'is_active' => true]);
    $organizer->users()->attach($member);
    $event = Event::factory()->create(['city_id' => $this->s->city->id, 'category_id' => $this->s->category->id, 'venue_id' => $this->s->venueA->id, 'organizer_id' => $organizer->id, 'status' => EventStatus::Draft]);

    Sanctum::actingAs($owner);
    $asOwner = $this->getJson('/api/v1/management/subjects')->assertOk()->assertJsonCount(0, 'data.venues')->json('data.organizers.0');
    Sanctum::actingAs($member);
    $asMember = $this->getJson('/api/v1/management/subjects')->assertOk()->json('data.organizers.0');

    expect($asOwner)->toMatchArray(['id' => $organizer->id, 'slug' => $organizer->slug, 'name' => 'Collettivo Portici', 'role' => 'owner'])
        ->and($asOwner['city'])->toBe(['name' => $this->s->city->name, 'timezone' => $this->s->city->timezone])
        ->and($asMember['role'])->toBe('member')
        ->and($asOwner['permissions'])->toMatchArray(['profile_update' => true, 'sponsorships_purchase' => true, 'events_publish_directly' => false, 'ticketing_manage' => true, 'collaborators_manage' => false, 'social_use' => false])
        ->and($asMember['permissions'])->toMatchArray(['profile_update' => false, 'sponsorships_purchase' => false, 'events_create' => true, 'ticketing_manage' => true]);

    // Tutti e due lavorano sugli eventi dell'organizzazione; un'organizzazione spenta non apre più niente.
    $this->postJson('/api/v1/management/organizers/'.$organizer->id.'/events/'.$event->id.'/poster', managementPoster())->assertOk();
    $organizer->update(['is_active' => false]);
    $this->deleteJson('/api/v1/management/organizers/'.$organizer->id.'/events/'.$event->id.'/poster')->assertNotFound();
});

it('fa leggere ai servizi condivisi il soggetto legato dall’API, senza il tenant di Filament', function (): void {
    $ownCampaign = Sponsorship::factory()->create(['event_id' => $this->s->publishedEventA->id]);
    $otherCampaign = Sponsorship::factory()->create(['event_id' => $this->s->publishedEventB->id]);
    $this->actingAs($this->s->ownerA);
    ManagementSubject::bind(app('request'), new ManagementSubject($this->s->venueA, $this->s->ownerA));

    expect(Filament::getTenant())->toBeNull()
        ->and(ManagementSubject::current()?->type())->toBe('venue')
        ->and(app(ManagementAnalytics::class)->subject()->is($this->s->venueA))->toBeTrue()
        ->and(app(ManagementAnalytics::class)->eventQuery()->pluck('id')->all())->toContain($this->s->publishedEventA->id)->not->toContain($this->s->publishedEventB->id)
        ->and(app(SponsorshipReport::class)->campaigns()->pluck('id')->all())->toContain($ownCampaign->id)->not->toContain($otherCampaign->id)
        ->and(app(SponsorshipReport::class)->timezone())->toBe('Europe/Rome')
        ->and(app(EventShares::class)->channels())->toContain(...EventShares::CHANNELS);
});
