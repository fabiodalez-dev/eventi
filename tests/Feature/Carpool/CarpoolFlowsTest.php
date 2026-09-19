<?php

declare(strict_types=1);

use App\Enums\RideRequestStatus;
use App\Enums\RideStatus;
use App\Models\CarpoolProfile;
use App\Models\RideConversation;
use App\Models\RideOffer;
use App\Services\Carpool\CarpoolAccess;
use App\Services\Carpool\CarpoolService;
use App\Services\Carpool\CarpoolTerms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('requires authentication for all mutations and conversations', function (): void {
    $this->postJson('/api/v1/carpool/actions/offer', [])->assertUnauthorized();
    $this->getJson('/api/v1/carpool/chats')->assertUnauthorized();
});

it('requires both contacts and adulthood', function (string $missing): void {
    if ($missing === 'adult') {
        CarpoolProfile::where('user_id', $this->driver->id)->delete();
    } else {
        $this->driver->forceFill([$missing => null])->save();
    }
    cpAction($this, $this->driver, 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'zone' => 'Centro', 'departure_at' => '2026-10-10T18:00:00Z',
        'capacity' => 2, 'accessibility' => 'not_specified', 'driver_declaration' => true])->assertForbidden();
    expect(RideOffer::count())->toBe(0);
})->with(['email_verified_at', 'whatsapp_verified_at', 'whatsapp_phone_hash', 'adult']);

it('does not require WhatsApp verification from an administrator, while retaining the adult and terms requirements', function (): void {
    $admin = cpStaff();
    $admin->forceFill(['whatsapp_verified_at' => null, 'whatsapp_phone_hash' => null])->save();

    expect(app(CarpoolAccess::class)->state($admin->fresh()))->toMatchArray(['eligible' => true, 'reason' => null]);

    cpAction($this, $admin, 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'zone' => 'Centro', 'departure_at' => '2026-10-10T18:00:00Z',
        'capacity' => 2, 'accessibility' => 'not_specified', 'driver_declaration' => true])->assertOk();
});

it('records separate explicit adult and legal acceptance with server metadata', function (): void {
    $user = carpoolPerson(false);
    cpAction($this, $user, 'declare', ['adult' => true, 'terms' => true, 'version' => config('carpool.terms_version')])->assertOk();
    $profile = CarpoolProfile::where('user_id', $user->id)->firstOrFail();
    expect($profile->adult_declared_at)->not->toBeNull()->and($profile->terms_hash)->toBe(app(CarpoolTerms::class)->hash());
});

it('rejects implicit or false declarations', function (array $values): void {
    cpAction($this, carpoolPerson(false), 'declare', ['version' => config('carpool.terms_version'), ...$values])->assertUnprocessable();
})->with([[['adult' => false, 'terms' => true]], [['adult' => true, 'terms' => false]], [['terms' => true]], [['adult' => true]]]);

it('leaves seats free while requests are pending and opens chat only on acceptance', function (): void {
    $offer = cpOffer($this);
    $request = cpRequest($this, $offer, seats: 2);
    expect(app(CarpoolService::class)->available($offer))->toBe(3)->and(RideConversation::count())->toBe(0);
    cpAction($this, $this->driver, 'accept', ['request_id' => $request->id])->assertOk();
    expect($request->fresh()->status)->toBe(RideRequestStatus::Accepted)->and(app(CarpoolService::class)->available($offer))->toBe(1)->and(RideConversation::count())->toBe(1);
    expect(DB::table('chat_participation')->count())->toBe(2);
});

it('requires the group adulthood declaration', function (): void {
    $offer = cpOffer($this);
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 2])->assertUnprocessable();
});

it('prevents requesting ones own offer', function (): void {
    $offer = cpOffer($this);
    cpAction($this, $this->driver, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertForbidden();
});

it('accepts only with the actual driver', function (): void {
    $request = cpRequest($this, cpOffer($this));
    cpAction($this, $this->passenger, 'accept', ['request_id' => $request->id])->assertForbidden();
    cpAction($this, carpoolPerson(), 'accept', ['request_id' => $request->id])->assertForbidden();
    expect($request->fresh()->status)->toBe(RideRequestStatus::Pending);
});

it('never overbooks pending group requests', function (): void {
    $offer = cpOffer($this);
    $a = cpRequest($this, $offer, seats: 2);
    $b = cpRequest($this, $offer, carpoolPerson(), 2);
    cpAction($this, $this->driver, 'accept', ['request_id' => $a->id])->assertOk();
    cpAction($this, $this->driver, 'accept', ['request_id' => $b->id])->assertConflict();
    expect(app(CarpoolService::class)->available($offer))->toBe(1);
});

it('replays acceptance without duplicate conversations notifications or seat assignments', function (): void {
    $request = cpRequest($this, cpOffer($this));
    $key = (string) Str::uuid();
    cpAction($this, $this->driver, 'accept', ['request_id' => $request->id, 'request_key' => $key])->assertOk();
    cpAction($this, $this->driver, 'accept', ['request_id' => $request->id, 'request_key' => $key])->assertOk();
    expect(RideConversation::count())->toBe(1)->and(DB::table('ride_occupancies')->count())->toBe(2);
    expect(DB::table('community_delivery_outbox')->where('dedupe_key', 'like', 'ride:accepted:%')->count())->toBe(1);
});

it('withdraws alternative requests when one is confirmed', function (): void {
    $a = cpRequest($this, cpOffer($this));
    $b = cpRequest($this, cpOffer($this, carpoolPerson()));
    cpAction($this, $this->driver, 'accept', ['request_id' => $a->id])->assertOk();
    expect($b->fresh()->status)->toBe(RideRequestStatus::Withdrawn);
});

it('allows independent outward and return bookings', function (): void {
    $out = cpOffer($this);
    $back = cpOffer($this, data: ['leg' => 'return', 'departure_at' => '2026-10-10T23:00:00Z']);
    $a = cpRequest($this, $out);
    $b = cpRequest($this, $back);
    cpAction($this, $this->driver, 'accept', ['request_id' => $a->id])->assertOk();
    cpAction($this, $this->driver, 'accept', ['request_id' => $b->id])->assertOk();
    expect(RideConversation::count())->toBe(2);
});

it('cancels once and retains the private read-only history', function (): void {
    $offer = cpOffer($this);
    $request = cpRequest($this, $offer, seats: 2);
    cpAction($this, $this->driver, 'accept', ['request_id' => $request->id])->assertOk();
    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $request->id])->assertOk();
    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $request->id])->assertOk();
    expect(app(CarpoolService::class)->available($offer))->toBe(3)->and(RideConversation::first()->read_only_at)->not->toBeNull();
});

it('revokes future agreements when phone verification disappears', function (): void {
    $offer = cpOffer($this);
    $request = cpRequest($this, $offer);
    cpAction($this, $this->driver, 'accept', ['request_id' => $request->id])->assertOk();
    $this->driver->forceFill(['whatsapp_verified_at' => null])->save();
    expect($offer->fresh()->status)->toBe(RideStatus::Cancelled)->and($request->fresh()->status)->toBe(RideRequestStatus::Cancelled);
});

it('makes a draft invisible and requires explicit publication', function (): void {
    $offer = cpOffer($this, data: ['draft' => true]);
    Sanctum::actingAs($this->passenger);
    $this->getJson('/api/v1/carpool/offers/'.$offer->id)->assertForbidden();
    expect(DB::table('ride_occupancies')->count())->toBe(0);
    cpAction($this, $this->driver, 'publish', ['offer_id' => $offer->id, 'driver_declaration' => true])->assertOk();
    expect($offer->fresh()->status)->toBe(RideStatus::Open);
});

it('encrypts messages and only permits the two accepted interlocutors', function (): void {
    $request = cpRequest($this, cpOffer($this));
    cpAction($this, $this->driver, 'accept', ['request_id' => $request->id])->assertOk();
    $chat = RideConversation::firstOrFail();
    Sanctum::actingAs($this->passenger);
    $this->postJson('/api/v1/carpool/chats/'.$chat->id.'/send', ['body' => 'Ci troviamo in piazza?', 'request_key' => (string) Str::uuid()])->assertOk();
    expect(DB::table('chat_messages')->value('body'))->not->toContain('Ci troviamo');
    $this->getJson('/api/v1/carpool/chats/'.$chat->id)->assertOk()->assertJsonPath('data.messages.0.body', 'Ci troviamo in piazza?');
    Sanctum::actingAs(carpoolPerson());
    $this->getJson('/api/v1/carpool/chats/'.$chat->id)->assertForbidden();
    $this->postJson('/api/v1/carpool/chats/'.$chat->id.'/send', ['body' => 'Intruso', 'request_key' => (string) Str::uuid()])->assertForbidden();
});

it('counts unread conversations once and preserves pending decisions', function (): void {
    $request = cpRequest($this, cpOffer($this));
    Sanctum::actingAs($this->driver);
    $this->getJson('/api/v1/carpool/summary')->assertOk()->assertJsonPath('data.pending', 1);
    cpAction($this, $this->driver, 'accept', ['request_id' => $request->id])->assertOk();
    $chat = RideConversation::firstOrFail();
    foreach (['Uno', 'Due'] as $body) {
        $this->postJson('/api/v1/carpool/chats/'.$chat->id.'/send', ['body' => $body, 'request_key' => (string) Str::uuid()])->assertOk();
    }
    Sanctum::actingAs($this->passenger);
    $this->getJson('/api/v1/carpool/summary')->assertOk()->assertJsonPath('data.conversations', 1)->assertJsonPath('data.pending', 0);
});
