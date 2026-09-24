<?php

use App\Models\Sponsorship;
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

it('assigns and revokes staff immediately and preserves check-in idempotency', function (): void {
    Sanctum::actingAs($this->owner);
    $this->postJson($this->base.'/staff', ['email' => $this->staff->email])->assertOk();
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.staff.0.email', $this->staff->email);
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
