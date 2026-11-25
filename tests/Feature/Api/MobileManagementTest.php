<?php

use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\User;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->date = occurrenceAt(testCity(), testCategory(), now()->addHours(2)->format('Y-m-d H:i:s'), now()->addHours(5)->format('Y-m-d H:i:s'), ['booking_enabled' => true, 'booking_capacity' => 10, 'booking_limit' => 10]);
    $this->date->event->venue->update(['ticketing_enabled' => true]);
    $this->owner = User::factory()->create();
    $this->staff = User::factory()->create();
    $this->date->event->venue->members()->attach($this->owner, ['role' => 'owner']);
    $this->base = '/api/v1/management/dates/'.$this->date->id;
});

it('lists only authorized dates and does not disclose management details to staff', function (): void {
    $this->getJson('/api/v1/management/dates')->assertUnauthorized();
    Sanctum::actingAs($this->staff);
    $this->getJson('/api/v1/management/dates')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->base)->assertForbidden();
    $this->date->checkinStaff()->attach($this->staff);
    $this->getJson('/api/v1/management/dates')->assertOk()->assertJsonPath('data.0.id', $this->date->id)->assertJsonPath('data.0.can_manage', false);
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.can_check_in', true)->assertJsonMissingPath('data.staff')->assertJsonMissingPath('data.statistics')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    $this->postJson($this->base.'/staff', ['email' => $this->owner->email])->assertForbidden();
    $this->patchJson($this->base.'/details', ['practical_details' => [], 'cost_breakdown' => []])->assertForbidden();
});

it('invites staff, grants access on acceptance, revokes it immediately and preserves check-in idempotency', function (): void {
    Sanctum::actingAs($this->owner);
    $this->postJson($this->base.'/staff', ['email' => $this->staff->email])->assertOk();
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.staff.0.email', mb_strtolower($this->staff->email))->assertJsonPath('data.staff.0.status', 'pending');
    acceptCheckinInvitation($this->date, $this->staff);
    Sanctum::actingAs($this->owner);
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.staff.0.status', 'accepted');
    $booking = app(TicketingService::class)->reserve(User::factory()->create(), $this->date, ['Ada'], (string) Str::uuid(), false);
    Sanctum::actingAs($this->staff);
    $payload = ['code' => $booking->tickets[0]->code, 'request_key' => (string) Str::uuid()];
    $url = '/api/v1/ticketing/'.$this->date->id.'/check-in';
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.status', 'checked_in');
    $this->postJson($url, $payload)->assertOk();
    $this->postJson($url, [...$payload, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
    Sanctum::actingAs($this->owner);
    $this->postJson($this->base.'/staff', ['email' => $this->staff->email, 'remove' => true])->assertOk();
    Sanctum::actingAs($this->staff);
    $this->postJson($url, $payload)->assertForbidden();
});

it('updates date details and validates costs without losing the zero versus unknown distinction', function (): void {
    Sanctum::actingAs($this->owner);
    $this->patchJson($this->base.'/details', ['practical_details' => ['accessibility' => 'unknown', 'food_notes' => 'Cucina fino alle 22'], 'cost_breakdown' => ['admission' => '12.50', 'drink' => 0, 'membership' => null]])->assertOk();
    $this->getJson($this->base)->assertJsonPath('data.cost_breakdown.drink', 0)->assertJsonPath('data.cost_breakdown.membership', null)->assertJsonPath('data.practical_details.accessibility', 'unknown');
    $this->patchJson($this->base.'/details', ['practical_details' => [], 'cost_breakdown' => ['admission' => -1]])->assertUnprocessable();
    $this->patchJson($this->base.'/details', ['practical_details' => ['unexpected' => 'x'], 'cost_breakdown' => []])->assertUnprocessable();
    $this->patchJson($this->base.'/details', ['practical_details' => [], 'cost_breakdown' => []])->assertOk();
});

it('restricts campaign economics to authorized venues and suppresses small samples', function (): void {
    $campaign = Sponsorship::factory()->create(['event_id' => $this->date->event_id, 'amount_cents' => 5000, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    Sanctum::actingAs($this->owner);
    $this->getJson('/api/v1/management/campaigns')->assertOk()->assertJsonPath('data.0.id', $campaign->id);
    $this->getJson('/api/v1/management/campaigns/'.$campaign->id)->assertOk()->assertJsonPath('data.economics.spend_cents', 5000)->assertJsonPath('data.economics.bookings', null)->assertJsonPath('data.economics.per_booking_cents', null);
    Sanctum::actingAs($this->staff);
    $this->getJson('/api/v1/management/campaigns')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/management/campaigns/'.$campaign->id)->assertNotFound();
});

it('adds the delivery progress to the managed campaigns without changing the old fields', function (): void {
    /* D70: le risposte di gestione guadagnano goal, delivered e pacing_state,
       solo come campi aggiunti. A periodo l'obiettivo e lo stato sono null. */
    $period = Sponsorship::factory()->create(['event_id' => $this->date->event_id, 'impressions' => 7, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    $goal = Sponsorship::factory()->create(['event_id' => $this->date->event_id, 'impressions' => 1000, 'impressions_goal' => 1000, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    Sanctum::actingAs($this->owner);

    $list = $this->getJson('/api/v1/management/campaigns')->assertOk()->json('data');
    $byId = collect($list)->keyBy('id');
    expect($byId[$period->id])->toHaveKeys(['id', 'title', 'starts_at', 'ends_at', 'goal', 'delivered', 'pacing_state'])
        ->and($byId[$period->id]['goal'])->toBeNull()
        ->and($byId[$period->id]['delivered'])->toBe(7)
        ->and($byId[$period->id]['pacing_state'])->toBeNull()
        ->and($byId[$goal->id]['goal'])->toBe(1000)
        ->and($byId[$goal->id]['delivered'])->toBe(1000)
        ->and($byId[$goal->id]['pacing_state'])->toBe('completed');

    $this->getJson('/api/v1/management/campaigns/'.$goal->id)->assertOk()
        ->assertJsonPath('data.economics.spend_cents', $goal->amount_cents)
        ->assertJsonPath('data.goal', 1000)
        ->assertJsonPath('data.delivered', 1000)
        ->assertJsonPath('data.pacing_state', 'completed');
});

it('replaces the whole date details on every PATCH, so an empty key means inherit', function (): void {
    /* Il comportamento voluto (piano android-gestione, 3.5): `PATCH …/details`
       è una sostituzione. Una chiave non mandata torna a «eredita dall'evento»,
       e le due chiavi principali devono esserci sempre. Le versioni dell'app
       già installate mandano sempre tutto: non si cambia. */
    Sanctum::actingAs($this->owner);
    $this->patchJson($this->base.'/details', ['practical_details' => ['food_notes' => 'Cucina fino alle 22', 'start_notes' => 'Si comincia puntuali'], 'cost_breakdown' => ['admission' => 10, 'drink' => 5]])->assertOk();
    $this->patchJson($this->base.'/details', ['practical_details' => ['food_notes' => 'Cucina fino alle 23'], 'cost_breakdown' => []])->assertOk();

    $this->getJson($this->base)->assertOk()
        ->assertJsonPath('data.practical_details.food_notes', 'Cucina fino alle 23')
        ->assertJsonMissingPath('data.practical_details.start_notes')
        ->assertJsonMissingPath('data.cost_breakdown.admission')
        ->assertJsonMissingPath('data.cost_breakdown.drink');
    $this->patchJson($this->base.'/details', ['practical_details' => ['food_notes' => 'Senza costi']])->assertUnprocessable();
});

it('lists the campaigns of the managed organizers as well, with the same rule as the sponsorship report', function (): void {
    $organizerOwner = User::factory()->create();
    $member = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->date->event->city_id, 'owner_id' => $organizerOwner->id, 'is_active' => true]);
    $organizer->users()->attach($member);
    $this->date->event->update(['organizer_id' => $organizer->id]);

    // Della redazione, pagata con un'abilitazione dell'organizzazione, pagata dal locale.
    $editorial = Sponsorship::factory()->create(['event_id' => $this->date->event_id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    $ownGrant = SponsorshipGrant::factory()->forOrganizer($organizer)->create();
    $paidByOrganizer = Sponsorship::factory()->forGrant($ownGrant)->create(['event_id' => $this->date->event_id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
    $venueGrant = SponsorshipGrant::factory()->create(['venue_id' => $this->date->event->venue_id]);
    $paidByVenue = Sponsorship::factory()->forGrant($venueGrant)->create(['event_id' => $this->date->event_id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

    foreach ([$organizerOwner, $member] as $person) {
        Sanctum::actingAs($person);
        $ids = collect($this->getJson('/api/v1/management/campaigns')->assertOk()->json('data'))->pluck('id')->all();
        expect($ids)->toContain($editorial->id, $paidByOrganizer->id)->not->toContain($paidByVenue->id);
        $this->getJson('/api/v1/management/campaigns/'.$paidByOrganizer->id)->assertOk()->assertJsonPath('data.id', $paidByOrganizer->id);
        $this->getJson('/api/v1/management/campaigns/'.$paidByVenue->id)->assertNotFound();
    }

    // Il referente del locale continua a vedere le campagne del locale, e non quelle pagate dall'organizzazione.
    Sanctum::actingAs($this->owner);
    $ids = collect($this->getJson('/api/v1/management/campaigns')->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toContain($editorial->id, $paidByVenue->id)->not->toContain($paidByOrganizer->id);

    // Un'organizzazione spenta non gestisce più niente.
    $organizer->update(['is_active' => false]);
    Sanctum::actingAs($organizerOwner);
    $this->getJson('/api/v1/management/campaigns')->assertOk()->assertJsonCount(0, 'data');
});
