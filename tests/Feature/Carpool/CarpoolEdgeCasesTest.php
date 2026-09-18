<?php

use App\Enums\OccurrenceStatus;
use App\Models\CarpoolProfile;
use App\Models\RideConversation;
use App\Models\RideFeedback;
use App\Models\RideOffer;
use App\Models\RideSearch;
use App\Models\RideTemplate;
use App\Services\Carpool\CarpoolAccess;
use App\Services\Carpool\CarpoolLifecycle;
use App\Services\Carpool\CarpoolService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('rejects invalid capacity and retains no partial offer or audit', function ($capacity): void {
    cpAction($this, $this->driver, 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'zone' => 'Centro',
        'departure_at' => '2026-10-10T18:00:00Z', 'capacity' => $capacity, 'accessibility' => 'not_specified', 'driver_declaration' => true])->assertUnprocessable();
    expect(RideOffer::count())->toBe(0)->and(DB::table('ride_occupancies')->count())->toBe(0);
})->with([0, -1, 9, 1.5, 'many']);

it('distinguishes the two practical wheelchair accessibility options', function (string $accessibility): void {
    $offer = cpOffer($this, data: ['accessibility' => $accessibility, 'accessibility_note' => 'Concordiamo il punto di incontro']);
    Sanctum::actingAs($this->passenger);
    $this->getJson('/api/v1/carpool/occurrences/'.$this->date->id.'?accessibility='.$accessibility)->assertOk()->assertJsonPath('data.offers.0.accessibility', $accessibility);
    $other = $accessibility === 'folding_chair' ? 'wheelchair_space' : 'folding_chair';
    $this->getJson('/api/v1/carpool/occurrences/'.$this->date->id.'?accessibility='.$other)->assertOk()->assertJsonCount(0, 'data.offers');
})->with(['folding_chair', 'wheelchair_space']);

it('never publishes an unverified user list to guests or unverified members', function (bool $guest): void {
    cpOffer($this);
    if ($guest) {
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');
    } else {
        $this->actingAs(carpoolPerson(false));
    }
    $this->getJson(route('carpool.dates', $this->date))->assertOk()->assertJsonCount(0, 'data.offers')->assertJsonPath('data.access.eligible', false);
})->with([true, false]);

it('rejects reused keys with changed payloads without touching the first command', function (): void {
    $key = (string) Str::uuid();
    cpAction($this, $this->driver, 'preferences', ['push_enabled' => false, 'request_key' => $key])->assertOk();
    cpAction($this, $this->driver, 'preferences', ['push_enabled' => true, 'request_key' => $key])->assertConflict();
    expect(CarpoolProfile::where('user_id', $this->driver->id)->first()->push_enabled)->toBeFalse();
});

it('requires the current terms version for new agreements', function (): void {
    config(['carpool.terms_version' => 'next-version']);
    cpAction($this, $this->driver, 'declare', ['adult' => true, 'terms' => true, 'version' => '2026-09-18'])->assertConflict();
    expect(app(CarpoolAccess::class)->eligible($this->driver))->toBeFalse();
});

it('allows safety withdrawals after the terms version changes', function (): void {
    $ride = cpAccepted($this);
    config(['carpool.terms_version' => 'next-version']);
    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $ride->id])->assertOk();
    expect($ride->fresh()->status->value)->toBe('withdrawn');
});

it('closes future offers after explicit adulthood rectification', function (): void {
    $ride = cpAccepted($this);
    cpAction($this, $this->driver, 'revoke-adult', ['confirm' => true])->assertOk();
    expect($ride->fresh()->status->value)->toBe('cancelled');
});

it('does not confirm a pending request after either participant loses eligibility', function (string $who): void {
    $ride = cpRequest($this, cpOffer($this));
    $this->{$who}->forceFill(['email_verified_at' => null])->save();
    cpAction($this, $this->driver, 'accept', ['request_id' => $ride->id])->assertStatus($who === 'driver' ? 403 : 409);
    expect(RideConversation::count())->toBe(0);
})->with(['driver', 'passenger']);

it('keeps current confirmations while closing new bookings', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer);
    cpAction($this, $this->driver, 'close', ['offer_id' => $offer->id])->assertOk();
    cpAction($this, $this->driver, 'accept', ['request_id' => $ride->id])->assertOk();
    cpAction($this, carpoolPerson(), 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertNotFound();
});

it('blocks a simultaneous driver and passenger role for the same date and leg', function (): void {
    $ride = cpAccepted($this);
    cpAction($this, $this->passenger, 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'zone' => 'Centro',
        'departure_at' => '2026-10-10T18:00:00Z', 'capacity' => 2, 'accessibility' => 'not_specified', 'driver_declaration' => true])->assertConflict();
});

it('limits pending alternatives to three per date and leg', function (): void {
    for ($i = 0; $i < 3; $i++) {
        cpRequest($this, cpOffer($this, carpoolPerson()));
    }
    $offer = cpOffer($this, carpoolPerson());
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertUnprocessable();
});

it('cancels old pending requests when offer conditions change', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer);
    cpAction($this, $this->driver, 'update', ['offer_id' => $offer->id, 'revision' => 1, 'zone' => 'Stazione', 'departure_at' => $offer->departure_at->toIso8601String(), 'capacity' => 3, 'accessibility' => 'not_specified'])->assertOk();
    expect($offer->fresh()->revision)->toBe(2)->and($ride->fresh()->status->value)->toBe('cancelled');
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertConflict();
});

it('rejects material changes to a confirmed offer', function (): void {
    $ride = cpAccepted($this);
    $offer = $ride->offer;
    cpAction($this, $this->driver, 'update', ['offer_id' => $offer->id, 'revision' => 1, 'zone' => 'Un altro luogo', 'departure_at' => $offer->departure_at->toIso8601String(), 'capacity' => 3, 'accessibility' => 'not_specified'])->assertConflict();
    expect($offer->fresh()->zone)->toBe('Prato della Valle');
});

it('never reduces capacity below already accepted seats', function (): void {
    $ride = cpAccepted($this, 3);
    $offer = $ride->offer;
    cpAction($this, $this->driver, 'update', ['offer_id' => $offer->id, 'revision' => 1, 'zone' => $offer->zone, 'departure_at' => $offer->departure_at->toIso8601String(), 'capacity' => 2, 'accessibility' => 'not_specified'])->assertConflict();
});

it('frees precisely the reduced number of seats without a new conversation', function (): void {
    $ride = cpAccepted($this, 3);
    cpAction($this, $this->passenger, 'reduce', ['request_id' => $ride->id, 'seats' => 1])->assertOk();
    expect(app(CarpoolService::class)->available($ride->offer))->toBe(2)->and(RideConversation::count())->toBe(1);
});

it('expires pending requests at departure and closes the chat after 24 hours', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer);
    $pending = cpRequest($this, $offer, carpoolPerson());
    cpAction($this, $this->driver, 'accept', ['request_id' => $ride->id])->assertOk();
    freezeLocal($this->city, '2026-10-10 20:00');
    app(CarpoolLifecycle::class)->tick();
    expect($pending->fresh()->status->value)->toBe('expired');
    freezeLocal($this->city, '2026-10-11 20:00');
    app(CarpoolLifecycle::class)->tick();
    expect($offer->fresh()->status->value)->toBe('completed')->and(RideConversation::first()->read_only_at)->not->toBeNull();
});

it('does not rewrite trip history through late cancellation or withdrawal', function (string $action): void {
    $ride = cpAccepted($this);
    freezeLocal($this->city, '2026-10-10 20:01');
    cpAction($this, $action === 'cancel' ? $this->driver : $this->passenger, $action, $action === 'cancel' ? ['offer_id' => $ride->ride_offer_id] : ['request_id' => $ride->id])->assertConflict();
})->with(['cancel', 'withdraw']);

it('invalidates future agreements for event cancellation or time changes', function (string $change): void {
    $ride = cpAccepted($this);
    if ($change === 'time') {
        $this->date->update(['starts_at' => $this->date->starts_at->addHour()]);
    } elseif ($change === 'deleted') {
        $this->date->delete();
    } else {
        $this->date->update(['status' => OccurrenceStatus::Cancelled]);
    }
    expect($ride->fresh()->status->value)->toBe('cancelled');
})->with(['time', 'cancelled', 'deleted']);

it('does not cancel rides for editorial title corrections', function (): void {
    $ride = cpAccepted($this);
    $this->date->event->update(['title' => 'Titolo aggiornato']);
    expect($ride->fresh()->status->value)->toBe('accepted');
});

it('validates intermediate stops instead of accepting arbitrary stop IDs', function (): void {
    $offer = cpOffer($this, data: ['stops' => ['Stazione', 'Piazza']]);
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1, 'stop_index' => 2])->assertUnprocessable();
});

it('allows at most three intermediate zones', function (): void {
    cpAction($this, $this->driver, 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'zone' => 'Centro',
        'departure_at' => '2026-10-10T18:00:00Z', 'capacity' => 2, 'accessibility' => 'not_specified', 'driver_declaration' => true, 'stops' => ['A', 'B', 'C', 'D']])->assertUnprocessable();
});

it('preserves escaping of user text in web ride views', function (): void {
    $offer = cpOffer($this, data: ['note' => '<script>alert(1)</script>']);
    $this->actingAs($this->passenger)->get(route('carpool.offer', $offer))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('copies only settings into a reusable ride template', function (): void {
    $offer = cpOffer($this);
    cpDiscovery($this, $this->driver, 'template', ['offer_id' => $offer->id, 'name' => 'Da casa'])->assertOk();
    $template = RideTemplate::firstOrFail();
    expect($template->settings)->not->toHaveKeys(['departure_at', 'occurrence_id', 'driver_id', 'status']);
    cpDiscovery($this, $this->passenger, 'delete-template', ['template_id' => $template->id])->assertNotFound();
});

it('disables a saved search after acceptance and rejects reactivation while occupied', function (): void {
    cpDiscovery($this, $this->passenger, 'search', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'zone' => 'Prato', 'seats' => 1,
        'accessibility' => 'not_specified', 'earliest_at' => '2026-10-10T17:00:00Z', 'latest_at' => '2026-10-10T19:00:00Z', 'is_public' => false, 'alerts_enabled' => true])->assertOk();
    cpAccepted($this);
    $search = RideSearch::firstOrFail();
    expect($search->active)->toBeFalse();
    cpDiscovery($this, $this->passenger, 'toggle-search', ['search_id' => $search->id, 'active' => true])->assertConflict();
});

it('requires an actual accepted ride before private feedback', function (): void {
    $ride = cpRequest($this, cpOffer($this));
    freezeLocal($this->city, '2026-10-11 20:00');
    cpDiscovery($this, $this->passenger, 'feedback', ['request_id' => $ride->id, 'kind' => 'no_show'])->assertForbidden();
});

it('deduplicates post-trip feedback and never exposes it as a public rating', function (): void {
    $ride = cpAccepted($this);
    freezeLocal($this->city, '2026-10-11 20:00');
    for ($i = 0; $i < 2; $i++) {
        cpDiscovery($this, $this->passenger, 'feedback', ['request_id' => $ride->id, 'kind' => 'travelled'])->assertOk();
    }
    expect(RideFeedback::count())->toBe(1);
    $this->getJson('/api/v1/carpool/requests/'.$ride->id)->assertOk()->assertJsonMissingPath('data.ride.feedback');
});
