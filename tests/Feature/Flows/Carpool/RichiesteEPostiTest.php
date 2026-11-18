<?php

declare(strict_types=1);

use App\Enums\RideRequestStatus;
use App\Enums\RideStatus;
use App\Models\CarpoolAudit;
use App\Models\CarpoolProfile;
use App\Models\RideConversation;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideSearch;
use App\Services\Carpool\CarpoolService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Flussi fra passeggero e conducente: richiesta, accettazione, rifiuto, posti esauriti,
 * rinuncia del passeggero, riduzione dei posti, annullamento dell'offerta con avviso ai
 * passeggeri, tappe e accompagnatori, requisiti che bloccano, alternative ritirate.
 */
require_once __DIR__.'/flussi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('il passeggero richiede dal sito, il conducente vede la richiesta e la accetta: si aprono posto e chat', function (): void {
    $offer = cpOffer($this);
    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee('Prato della Valle');

    $response = $this->post(route('carpool.action', 'request'), ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id, 'revision' => 1,
        'seats' => 1, 'note' => 'Porto solo uno zaino']);
    $ride = RideRequest::firstOrFail();
    $response->assertRedirect(route('carpool.request', $ride));
    expect($ride->status)->toBe(RideRequestStatus::Pending)->and($ride->user_id)->toBe($this->passenger->id)
        ->and($ride->offer_revision)->toBe(1)->and(RideConversation::count())->toBe(0)
        ->and($this->driver->notifications()->where('type', 'carpool.requested')->count())->toBe(1)
        ->and(DB::table('community_delivery_outbox')->where('dedupe_key', 'ride:requested:'.$ride->id.':'.$this->driver->id)->count())->toBe(1);
    $this->get(route('carpool.request', $ride))->assertOk()->assertSee('Porto solo uno zaino');

    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.summary'))->assertOk()->assertJsonPath('data.pending', 1)->assertJsonPath('data.unread', 1);
    $this->getJson(route('carpool.offer', $offer))->assertOk()->assertJsonCount(1, 'data.requests')
        ->assertJsonPath('data.requests.0.status', 'pending')->assertJsonPath('data.requests.0.can_decide', true);
    $this->get(route('carpool.request', $ride))->assertOk();

    $this->post(route('carpool.action', 'accept'), ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id])->assertRedirect(route('carpool.request', $ride));

    $ride->refresh();
    $chat = RideConversation::where('ride_request_id', $ride->id)->firstOrFail();
    expect($ride->status)->toBe(RideRequestStatus::Accepted)->and($ride->accepted_at)->not->toBeNull()
        ->and(app(CarpoolService::class)->available($offer))->toBe(2)
        ->and(DB::table('ride_occupancies')->where('user_id', $this->passenger->id)->where('ride_request_id', $ride->id)->count())->toBe(1)
        ->and(DB::table('ride_chat_preferences')->where('ride_conversation_id', $chat->id)->count())->toBe(2)
        ->and($this->passenger->notifications()->where('type', 'carpool.accepted')->count())->toBe(1)
        ->and(CarpoolAudit::where('action', 'request_accepted')->where('subject_id', $ride->id)->count())->toBe(1);
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.summary'))->assertJsonPath('data.pending', 0);

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.index').'?tab=requested')->assertOk()->assertJsonCount(1, 'data.requests')
        ->assertJsonPath('data.requests.0.status', 'accepted')->assertJsonPath('data.requests.0.chat_id', $chat->id);
    $this->get(route('carpool.index'))->assertOk();
});

it('il conducente rifiuta: il passeggero è avvisato, non nasce chat e non può insistere sulla stessa offerta', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer);

    // Il passeggero non può decidere sulla propria richiesta.
    cpAction($this, $this->passenger, 'decline', ['request_id' => $ride->id])->assertForbidden();
    cpAction($this, $this->driver, 'decline', ['request_id' => $ride->id])->assertOk()->assertJsonPath('data.entity', 'request')->assertJsonPath('summary.pending', 0);

    $ride->refresh();
    expect($ride->status)->toBe(RideRequestStatus::Declined)->and($ride->close_reason)->toBe('driver_declined')->and($ride->active_key)->toBeNull()
        ->and(RideConversation::count())->toBe(0)
        ->and($this->passenger->notifications()->where('type', 'carpool.declined')->count())->toBe(1)
        ->and(DB::table('ride_occupancies')->where('user_id', $this->passenger->id)->count())->toBe(0);

    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertConflict()
        ->assertJsonPath('error.message', __('carpool.errors.declined'));
    // Una decisione già presa non si ribalta.
    cpAction($this, $this->driver, 'accept', ['request_id' => $ride->id])->assertConflict();
    expect(RideRequest::count())->toBe(1);
});

it('quando i posti finiscono la seconda accettazione e le nuove richieste vengono respinte e l\'offerta sparisce dalla ricerca', function (): void {
    $offer = cpOffer($this, data: ['capacity' => 2]);
    $group = cpRequest($this, $offer, seats: 2);
    $other = carpoolPerson();
    $single = cpRequest($this, $offer, $other);
    // Le richieste in attesa non occupano posti.
    expect(app(CarpoolService::class)->available($offer))->toBe(2);

    cpAction($this, $this->driver, 'accept', ['request_id' => $group->id])->assertOk();
    expect(app(CarpoolService::class)->available($offer))->toBe(0);

    cpAction($this, $this->driver, 'accept', ['request_id' => $single->id])->assertConflict()->assertJsonPath('error.message', __('carpool.errors.capacity'));
    expect($single->fresh()->status)->toBe(RideRequestStatus::Pending);

    $late = carpoolPerson();
    cpAction($this, $late, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertConflict();
    flussoCpWeb($this, $late);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(0, 'data.offers');

    Sanctum::actingAs($this->driver->fresh());
    $this->getJson('/api/v1/carpool/offers/'.$offer->id)->assertOk()->assertJsonPath('data.offer.available', 0)->assertJsonPath('data.offer.can_request', false)
        ->assertJsonCount(2, 'data.requests');
    expect(RideRequest::count())->toBe(2)->and(RideConversation::count())->toBe(1);
});

it('il passeggero rinuncia a un passaggio confermato: il posto si libera, la chat diventa di sola lettura e c\'è un tetto ai ripensamenti', function (): void {
    $ride = cpAccepted($this);
    $offer = $ride->offer;
    flussoCpWeb($this, $this->passenger);

    $this->post(route('carpool.action', 'withdraw'), ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id])->assertRedirect(route('carpool.request', $ride));
    $ride->refresh();
    expect($ride->status)->toBe(RideRequestStatus::Withdrawn)->and($ride->close_reason)->toBe('passenger_withdrew')
        ->and(app(CarpoolService::class)->available($offer))->toBe(3)
        ->and(DB::table('ride_occupancies')->where('user_id', $this->passenger->id)->count())->toBe(0)
        ->and(RideConversation::firstOrFail()->read_only_at)->not->toBeNull()
        ->and($this->driver->notifications()->where('type', 'carpool.withdrawn')->count())->toBe(1);
    // Il conducente non può ritirare la richiesta altrui.
    cpAction($this, $this->driver, 'withdraw', ['request_id' => $ride->id])->assertForbidden();

    $again = cpRequest($this, $offer->fresh());
    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $again->id])->assertOk();
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertConflict()
        ->assertJsonPath('error.message', __('carpool.errors.withdrawals'));
    expect(RideRequest::where('user_id', $this->passenger->id)->count())->toBe(2);
});

it('il passeggero riduce i posti di un gruppo già confermato e il conducente viene avvisato', function (): void {
    $ride = cpAccepted($this, 3);
    expect(app(CarpoolService::class)->available($ride->offer))->toBe(0);

    cpAction($this, $this->passenger, 'reduce', ['request_id' => $ride->id, 'seats' => 1])->assertOk();
    expect($ride->fresh()->seats)->toBe(1)->and(app(CarpoolService::class)->available($ride->offer))->toBe(2)
        ->and($this->driver->notifications()->where('type', 'carpool.reduced')->count())->toBe(1)
        ->and(DB::table('community_delivery_outbox')->where('dedupe_key', 'ride:reduced:'.$ride->id.':1:'.$this->driver->id)->count())->toBe(1)
        ->and(RideConversation::count())->toBe(1);

    // Non si "riduce" aumentando, e il conducente non riduce per conto del passeggero.
    cpAction($this, $this->passenger, 'reduce', ['request_id' => $ride->id, 'seats' => 2])->assertUnprocessable();
    cpAction($this, $this->driver, 'reduce', ['request_id' => $ride->id, 'seats' => 1])->assertForbidden();
    // Ora c'è posto per un altro passeggero.
    $other = cpRequest($this, $ride->offer->fresh(), carpoolPerson(), 2);
    cpAction($this, $this->driver, 'accept', ['request_id' => $other->id])->assertOk();
});

it('il conducente annulla l\'offerta: confermati e in attesa sono avvisati e la ritrovano nello storico', function (): void {
    $offer = cpOffer($this);
    $accepted = cpRequest($this, $offer);
    cpAction($this, $this->driver, 'accept', ['request_id' => $accepted->id])->assertOk();
    $waiting = carpoolPerson();
    $pending = cpRequest($this, $offer, $waiting);

    flussoCpWeb($this, $this->driver);
    $this->post(route('carpool.action', 'cancel'), ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id])->assertRedirect(route('carpool.offer', $offer));

    $offer->refresh();
    expect($offer->status)->toBe(RideStatus::Cancelled)->and($offer->close_reason)->toBe('driver_cancelled')->and($offer->active_key)->toBeNull()
        ->and($accepted->fresh()->status)->toBe(RideRequestStatus::Cancelled)->and($pending->fresh()->status)->toBe(RideRequestStatus::Cancelled)
        ->and(DB::table('ride_occupancies')->count())->toBe(0)
        ->and(RideConversation::firstOrFail()->read_only_at)->not->toBeNull()
        ->and($this->passenger->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1)
        ->and($waiting->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1)
        ->and($this->driver->notifications()->where('type', 'carpool.cancelled')->count())->toBe(0)
        ->and(CarpoolAudit::where('action', 'ride_cancelled')->where('subject_id', $offer->id)->count())->toBe(1);

    // Un secondo annullamento non produce nulla di nuovo.
    cpAction($this, $this->driver, 'cancel', ['offer_id' => $offer->id])->assertOk();
    expect($this->passenger->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1);

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.index').'?tab=requested')->assertJsonCount(0, 'data.requests');
    $this->getJson(route('carpool.index').'?tab=history')->assertJsonCount(1, 'data.requests')->assertJsonPath('data.requests.0.status', 'cancelled');
    $this->get(route('carpool.index').'?tab=history')->assertOk();
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(0, 'data.offers');
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.index').'?tab=history')->assertJsonPath('data.offers.0.status', 'cancelled');
});

it('chi viaggia in gruppo dichiara gli accompagnatori e sceglie una tappa; doppioni e la propria offerta sono respinti', function (): void {
    $offer = cpOffer($this, data: ['stops' => ['Stazione', 'Piazza Garibaldi']]);

    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 2])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['companions_adult']]]);
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1, 'stop_index' => 2])->assertUnprocessable();
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 4, 'companions_adult' => true])->assertConflict();

    $ride = cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 2, 'companions_adult' => true, 'stop_index' => 1])->assertOk();
    $request = RideRequest::findOrFail($ride->json('data.id'));
    expect($request->seats)->toBe(2)->and($request->companions_adult)->toBeTrue()->and($request->stop_index)->toBe(1);

    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertConflict()->assertJsonPath('error.message', __('carpool.errors.duplicate'));
    cpAction($this, $this->driver, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertForbidden();

    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/requests/'.$request->id)->assertOk()->assertJsonPath('data.ride.companions', 1)->assertJsonPath('data.ride.stop_index', 1)
        ->assertJsonPath('data.offer.stops.1', 'Piazza Garibaldi');
    Sanctum::actingAs(carpoolPerson());
    $this->getJson('/api/v1/carpool/requests/'.$request->id)->assertForbidden();
    expect(RideRequest::count())->toBe(1);
});

it('requisiti mancanti bloccano richiesta, offerta e ricerca finché la persona non li completa; la rettifica dell\'età li ritoglie', function (): void {
    $offer = cpOffer($this);
    $newcomer = carpoolPerson(false);

    flussoCpWeb($this, $newcomer);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonPath('data.access.reason', 'adult')->assertJsonPath('data.offers', []);
    $this->get(route('carpool.dates', $this->date))->assertOk()->assertDontSee('Prato della Valle');
    cpAction($this, $newcomer, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertForbidden();
    cpAction($this, $newcomer, 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'return', 'zone' => 'Centro', 'departure_at' => '2026-10-10T23:00:00Z',
        'capacity' => 2, 'accessibility' => 'not_specified', 'driver_declaration' => true])->assertForbidden();
    cpDiscovery($this, $newcomer, 'search', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'seats' => 1, 'accessibility' => 'not_specified',
        'earliest_at' => '2026-10-10T17:00:00Z', 'latest_at' => '2026-10-10T19:00:00Z', 'is_public' => true, 'alerts_enabled' => true])->assertForbidden();
    expect(RideRequest::count())->toBe(0)->and(RideOffer::count())->toBe(1)->and(RideSearch::count())->toBe(0);

    // Una persona sospesa vede il motivo e resta bloccata anche con la dichiarazione.
    $suspended = carpoolPerson();
    $suspended->forceFill(['carpool_suspended_at' => now()])->save();
    flussoCpWeb($this, $suspended);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonPath('data.access.reason', 'suspended');
    cpAction($this, $suspended, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertForbidden();

    cpAction($this, $newcomer, 'declare', ['adult' => true, 'terms' => true, 'version' => config('carpool.terms_version')])->assertOk()->assertJsonPath('data.entity', 'profile');
    $ride = cpRequest($this, $offer, $newcomer);
    expect($ride->status)->toBe(RideRequestStatus::Pending);

    cpAction($this, $newcomer, 'revoke-adult', [])->assertUnprocessable();
    cpAction($this, $newcomer, 'revoke-adult', ['confirm' => true])->assertOk();
    expect($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled)->and($ride->fresh()->close_reason)->toBe('requirements_or_block_changed')
        ->and(CarpoolProfile::where('user_id', $newcomer->id)->value('adult_declared_at'))->toBeNull()
        ->and($this->driver->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1);
    flussoCpWeb($this, $newcomer);
    $this->getJson(route('carpool.requirements'))->assertJsonPath('data.access.reason', 'adult');
});

it('accettare una richiesta ritira le alternative in attesa e spegne la ricerca salvata della stessa tratta', function (): void {
    cpDiscovery($this, $this->passenger, 'search', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'seats' => 1, 'accessibility' => 'not_specified',
        'earliest_at' => '2026-10-10T17:00:00Z', 'latest_at' => '2026-10-10T19:00:00Z', 'is_public' => true, 'alerts_enabled' => true])->assertOk();
    $returnSearch = cpDiscovery($this, $this->passenger, 'search', ['occurrence_id' => $this->date->id, 'leg' => 'return', 'seats' => 1, 'accessibility' => 'not_specified',
        'earliest_at' => '2026-10-10T22:30:00Z', 'latest_at' => '2026-10-10T23:30:00Z', 'is_public' => false, 'alerts_enabled' => true])->assertOk();
    $otherDriver = carpoolPerson();
    $first = cpRequest($this, cpOffer($this));
    $second = cpRequest($this, cpOffer($this, $otherDriver, ['zone' => 'Stazione']));

    cpAction($this, $this->driver, 'accept', ['request_id' => $first->id])->assertOk();

    expect($second->fresh()->status)->toBe(RideRequestStatus::Withdrawn)->and($second->fresh()->close_reason)->toBe('alternative_accepted')
        ->and($otherDriver->notifications()->where('type', 'carpool.withdrawn')->count())->toBe(1)
        ->and($this->passenger->notifications()->where('type', 'carpool.withdrawn')->count())->toBe(0)
        ->and(RideSearch::where('leg', 'outbound')->value('active'))->toBeFalse()
        ->and(RideSearch::findOrFail($returnSearch->json('data.id'))->active)->toBeTrue();

    // L'altro conducente non può più accettarla.
    cpAction($this, $otherDriver, 'accept', ['request_id' => $second->id])->assertConflict();
    // Né il passeggero può offrire sulla tratta che occupa già da passeggero.
    cpAction($this, $this->passenger, 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'zone' => 'Centro', 'departure_at' => '2026-10-10T18:00:00Z',
        'capacity' => 2, 'accessibility' => 'not_specified', 'driver_declaration' => true])->assertConflict();
});
