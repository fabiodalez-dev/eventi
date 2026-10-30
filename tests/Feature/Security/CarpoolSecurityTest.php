<?php

declare(strict_types=1);

use App\Enums\ProfileVisibility;
use App\Models\CarpoolCase;
use App\Models\RideConversation;
use App\Models\RideOffer;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Carpool\RideChat;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Regressioni di sicurezza dei passaggi: nome anagrafico esposto e oracolo delle recensioni,
 * ciclo richiedi-ritira verso un conducente, dati correnti dell'offerta visibili a chi è bloccato.
 * Gli helper cp* stanno in tests/Support/Carpool.php.
 */
beforeEach(function (): void {
    cpSetup($this);
    $this->driver->forceFill(['first_name' => 'Mario', 'last_name' => 'Rossi', 'name' => 'Mario Rossi'])->save();
    $this->passenger->forceFill(['first_name' => 'Giulia', 'last_name' => 'Bianchi', 'name' => 'Giulia Bianchi'])->save();
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function securityCpReviewed($test): void
{
    $ride = cpAccepted($test);
    freezeLocal($test->city, '2026-10-11 12:00');
    Sanctum::actingAs($test->passenger->fresh());
    $test->postJson('/api/v1/carpool/requests/'.$ride->id.'/review/confirm', ['request_key' => (string) Str::uuid(), 'confirm' => true])->assertOk();
    $test->postJson('/api/v1/carpool/requests/'.$ride->id.'/review/save', ['request_key' => (string) Str::uuid(), 'rating' => 4, 'body' => 'Puntuale', 'revision' => 0])->assertOk();
}

it('never shows the full registered name of drivers, requesters or review authors', function (): void {
    securityCpReviewed($this);
    Sanctum::actingAs(carpoolPerson());
    $this->getJson('/api/v1/carpool/drivers/'.$this->driver->id.'/reviews')->assertOk()
        ->assertJsonPath('data.driver_name', 'Mario')->assertJsonPath('data.reviews.0.name', 'Giulia')
        ->assertDontSee('Rossi')->assertDontSee('Bianchi');

    $offer = RideOffer::firstOrFail();
    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/offers/'.$offer->id)->assertOk()->assertJsonPath('data.offer.driver.name', 'Mario')->assertDontSee('Rossi');
    Sanctum::actingAs($this->driver->fresh());
    $this->getJson('/api/v1/carpool/offers/'.$offer->id)->assertOk()->assertJsonPath('data.requests.0.requester.name', 'Giulia')->assertDontSee('Bianchi');
});

it('hides the full name in the public «looking for a ride» list', function (): void {
    cpDiscovery($this, $this->passenger, 'search', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'zone' => 'Arcella', 'seats' => 1,
        'accessibility' => 'not_specified', 'earliest_at' => '2026-10-10T17:00:00Z', 'latest_at' => '2026-10-10T19:00:00Z', 'is_public' => true, 'alerts_enabled' => false])->assertOk();
    Sanctum::actingAs($this->driver->fresh());
    $this->getJson('/api/v1/carpool/occurrences/'.$this->date->id)->assertOk()->assertJsonPath('data.wanted.0.name', 'Giulia')->assertDontSee('Bianchi');
});

it('does not lend the name of a private community profile to the carpool pages', function (): void {
    securityCpReviewed($this);
    $this->driver->communityProfile()->create(['handle' => 'segreto_'.$this->driver->id, 'display_name' => 'Pseudonimo segreto', 'visibility' => ProfileVisibility::Private]);
    Sanctum::actingAs(carpoolPerson());
    $this->getJson('/api/v1/carpool/drivers/'.$this->driver->id.'/reviews')->assertOk()
        ->assertJsonPath('data.driver_name', 'Mario')->assertDontSee('Pseudonimo segreto')->assertDontSee('Rossi');
});

it('does not open reviews of verified users who never offered a ride', function (): void {
    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/drivers/'.carpoolPerson()->id.'/reviews')->assertNotFound();
    // Stessa risposta per chi non ha nemmeno verificato il telefono: niente oracolo sulle verifiche.
    $this->getJson('/api/v1/carpool/drivers/'.User::factory()->create()->id.'/reviews')->assertNotFound();
});

it('throttles the driver reviews page on the website', function (): void {
    cpOffer($this);
    RateLimiter::clear('carpool-read:'.$this->passenger->id);
    $this->actingAs($this->passenger->fresh());
    foreach (range(1, 60) as $i) {
        $this->getJson(route('carpool.reviews', $this->driver->id))->assertOk();
    }
    $this->getJson(route('carpool.reviews', $this->driver->id))->assertTooManyRequests();
});

it('refuses a new request on the same offer after the driver declined it', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer);
    cpAction($this, $this->driver, 'decline', ['request_id' => $ride->id])->assertOk();
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => $offer->fresh()->revision, 'seats' => 1])->assertConflict();
    expect($this->driver->notifications()->where('type', 'carpool.requested')->count())->toBe(1);
});

it('caps the request-withdraw cycle on the same offer but still allows a change of mind', function (): void {
    $offer = cpOffer($this);
    foreach (range(1, 2) as $i) {
        $ride = cpRequest($this, $offer->fresh());
        cpAction($this, $this->passenger, 'withdraw', ['request_id' => $ride->id])->assertOk();
    }
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => $offer->fresh()->revision, 'seats' => 1])->assertConflict();
    expect($this->driver->notifications()->where('type', 'carpool.withdrawn')->count())->toBe(2)
        ->and($this->driver->notifications()->where('type', 'carpool.requested')->count())->toBe(2);
    // Il vincolo vale per l'offerta: un altro passaggio resta richiedibile.
    cpRequest($this, cpOffer($this, carpoolPerson()));
});

it('hides the current offer details from a passenger once a block exists', function (bool $driverBlocks): void {
    $ride = cpAccepted($this);
    UserBlock::create($driverBlocks ? ['user_id' => $this->driver->id, 'blocked_user_id' => $this->passenger->id] : ['user_id' => $this->passenger->id, 'blocked_user_id' => $this->driver->id]);
    $offer = RideOffer::findOrFail($ride->ride_offer_id);
    cpAction($this, $this->driver, 'update', ['offer_id' => $offer->id, 'revision' => $offer->revision, 'zone' => 'Nuova zona segreta',
        'departure_at' => '2026-10-10T17:30:00Z', 'capacity' => 3, 'accessibility' => 'not_specified', 'stops' => ['Tappa segreta']])->assertOk();

    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/offers/'.$offer->id)->assertForbidden();
    $this->getJson('/api/v1/carpool/requests/'.$ride->id)->assertOk()->assertJsonPath('data.offer.zone', null)
        ->assertJsonPath('data.offer.departure_at', null)->assertJsonPath('data.ride.id', $ride->id)->assertDontSee('Nuova zona segreta')->assertDontSee('Tappa segreta');
    $this->getJson('/api/v1/carpool/me')->assertOk()->assertDontSee('Nuova zona segreta')->assertDontSee('Tappa segreta');

    Sanctum::actingAs($this->driver->fresh());
    $this->getJson('/api/v1/carpool/offers/'.$offer->id)->assertOk()->assertJsonPath('data.offer.zone', 'Nuova zona segreta');
})->with(['driver blocks' => true, 'passenger blocks' => false]);

it('keeps the offer visible to a passenger with a closed request and no block', function (): void {
    $ride = cpAccepted($this);
    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $ride->id])->assertOk();
    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/offers/'.$ride->ride_offer_id)->assertOk()->assertJsonPath('data.offer.zone', 'Prato della Valle');
});

it('lets a participant report the ride and its chat messages after a block', function (bool $driverBlocks): void {
    $ride = cpAccepted($this);
    $chat = RideConversation::firstOrFail();
    $messageId = app(RideChat::class)->send($this->driver, $chat, 'Messaggio molesto', (string) Str::uuid());
    $otherOffer = cpOffer($this, carpoolPerson());
    UserBlock::create($driverBlocks ? ['user_id' => $this->driver->id, 'blocked_user_id' => $this->passenger->id] : ['user_id' => $this->passenger->id, 'blocked_user_id' => $this->driver->id]);

    Sanctum::actingAs($this->passenger->fresh());
    $report = fn (array $data) => $this->postJson('/api/v1/carpool/reports', ['request_key' => (string) Str::uuid(), 'reason' => 'safety', 'body' => 'Messaggi molesti in chat.', ...$data]);
    $report(['offer_id' => $ride->ride_offer_id, 'request_id' => $ride->id])->assertOk();
    $report(['offer_id' => $ride->ride_offer_id, 'request_id' => $ride->id, 'message_id' => $messageId])->assertOk();
    expect(CarpoolCase::where('reporter_id', $this->passenger->id)->where('ride_request_id', $ride->id)->count())->toBe(2)
        ->and(CarpoolCase::where('message_id', $messageId)->exists())->toBeTrue();
    // Senza la richiesta l'offerta resta nascosta, e non si accoppia una richiesta a un'altra offerta.
    $report(['offer_id' => $ride->ride_offer_id])->assertNotFound();
    $report(['offer_id' => $otherOffer->id, 'request_id' => $ride->id])->assertUnprocessable();
})->with(['driver blocks' => true, 'passenger blocks' => false]);

it('caps the request-block-unblock cycle against the same driver', function (): void {
    $offer = cpOffer($this);
    foreach (range(1, 2) as $i) {
        cpRequest($this, $offer->fresh());
        Sanctum::actingAs($this->passenger->fresh());
        $this->postJson('/api/v1/community/people/'.$this->driver->id.'/block')->assertOk();
        $this->deleteJson('/api/v1/community/people/'.$this->driver->id.'/block')->assertOk();
    }
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => $offer->fresh()->revision, 'seats' => 1])
        ->assertConflict()->assertJsonPath('error.message', __('carpool.errors.withdrawals'));
    expect($this->driver->notifications()->where('type', 'carpool.requested')->count())->toBe(2);
});

it('links the ride reviews from a community profile only for people who offered a ride', function (): void {
    config(['community.enabled' => true]);
    $this->driver->communityProfile()->create(['handle' => 'conducente_'.$this->driver->id, 'display_name' => 'Conducente', 'visibility' => ProfileVisibility::Public]);
    $this->actingAs($this->passenger->fresh());
    $this->get(route('community.profile', 'conducente_'.$this->driver->id))->assertOk()->assertDontSee(route('carpool.reviews', $this->driver->id));
    cpOffer($this);
    $this->actingAs($this->passenger->fresh());
    $this->get(route('community.profile', 'conducente_'.$this->driver->id))->assertOk()->assertSee(route('carpool.reviews', $this->driver->id));
    $this->get(route('carpool.reviews', $this->driver->id))->assertOk();
});
