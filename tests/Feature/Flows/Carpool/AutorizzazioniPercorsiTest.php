<?php

declare(strict_types=1);

use App\Enums\RideRequestStatus;
use App\Models\RideFeedback;
use App\Models\RideReview;
use App\Models\RideSearch;
use App\Models\RideTemplate;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Chi non c'entra, dal sito e dall'app: un estraneo, un altro passeggero della stessa
 * offerta, un conducente di un'altra offerta. Protegge che nessuno legga o cambi richieste,
 * chat, casi, ricerche, modelli, feedback e recensioni che non sono suoi, e che i tentativi
 * non lascino tracce nel database.
 */
require_once __DIR__.'/percorsi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('un estraneo e un conducente di un\'altra offerta non vedono richiesta, chat e caso altrui, né dal sito né dall\'app', function (): void {
    $ride = cpAccepted($this);
    $chat = percorsoCpChat($ride);
    $case = cpCase($this, $ride);
    $otherDriver = carpoolPerson();
    $otherOffer = cpOffer($this, $otherDriver, ['leg' => 'return', 'zone' => 'Centro', 'departure_at' => '2026-10-10T23:00:00Z']);
    cpRequest($this, $otherOffer);

    foreach ([carpoolPerson(), $otherDriver] as $outsider) {
        flussoCpWeb($this, $outsider);
        $this->get(route('carpool.request', $ride))->assertForbidden();
        $this->get(route('carpool.chat', $chat))->assertForbidden();
        $this->get(route('carpool.case', $case))->assertForbidden();
        $this->getJson(route('carpool.cases'))->assertJsonCount(0, 'data.cases');
        Sanctum::actingAs($outsider->fresh());
        $this->getJson('/api/v1/carpool/requests/'.$ride->id)->assertForbidden();
        $this->getJson('/api/v1/carpool/chats/'.$chat->id)->assertForbidden();
        $this->getJson('/api/v1/carpool/cases/'.$case->id)->assertForbidden();
        $this->postJson('/api/v1/carpool/chats/'.$chat->id.'/preferences', ['muted' => true])->assertForbidden();
    }
    flussoCpWeb($this, $otherDriver);
    $this->getJson(route('carpool.index'))->assertJsonCount(0, 'data.requests');
    expect(DB::table('ride_chat_preferences')->where('user_id', $otherDriver->id)->count())->toBe(0);
});

it('un passeggero non decide, non riduce e non ritira le richieste degli altri passeggeri della stessa offerta', function (): void {
    $offer = cpOffer($this);
    $mine = cpRequest($this, $offer, seats: 2);
    $other = carpoolPerson();
    $theirs = cpRequest($this, $offer, $other);
    cpAction($this, $this->driver, 'accept', ['request_id' => $theirs->id])->assertOk();

    cpAction($this, $this->passenger, 'decline', ['request_id' => $theirs->id])->assertForbidden();
    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $theirs->id])->assertForbidden();
    cpAction($this, $this->passenger, 'reduce', ['request_id' => $theirs->id, 'seats' => 1])->assertForbidden();
    cpAction($this, $other, 'accept', ['request_id' => $mine->id])->assertForbidden();
    percorsoCpPost($this, $other, 'withdraw', ['request_id' => $mine->id])->assertForbidden();

    expect($theirs->fresh()->status)->toBe(RideRequestStatus::Accepted)->and($mine->fresh()->status)->toBe(RideRequestStatus::Pending)
        ->and($mine->fresh()->seats)->toBe(2);
    flussoCpWeb($this, $other);
    $this->get(route('carpool.request', $mine))->assertForbidden();
});

it('nessuno spegne la ricerca, usa il modello o lascia un feedback su cose di un altro', function (): void {
    $ride = cpAccepted($this);
    $search = cpDiscovery($this, $this->passenger, 'search', percorsoCpSearch($this, ['leg' => 'return', 'earliest_at' => '2026-10-10T22:30:00Z', 'latest_at' => '2026-10-10T23:30:00Z']))
        ->assertOk()->json('data.id');
    $template = cpDiscovery($this, $this->driver, 'template', ['offer_id' => $ride->ride_offer_id, 'name' => 'Il mio giro'])->assertOk()->json('data.id');
    $stranger = carpoolPerson();

    cpDiscovery($this, $stranger, 'toggle-search', ['search_id' => $search, 'active' => false])->assertNotFound();
    cpDiscovery($this, $stranger, 'delete-template', ['template_id' => $template])->assertNotFound();
    cpDiscovery($this, $stranger, 'template', ['offer_id' => $ride->ride_offer_id, 'name' => 'Copiato'])->assertNotFound();
    freezeLocal($this->city, '2026-10-11 21:00');
    cpDiscovery($this, $stranger, 'feedback', ['request_id' => $ride->id, 'kind' => 'problem'])->assertForbidden();
    flussoCpWeb($this, $stranger);
    $this->post(route('carpool.discovery', 'toggle-search'), ['request_key' => (string) Str::uuid(), 'search_id' => $search, 'active' => '0'])->assertNotFound();

    expect(RideSearch::findOrFail($search)->active)->toBeTrue()->and(RideTemplate::count())->toBe(1)->and(RideFeedback::count())->toBe(0);
    Sanctum::actingAs($stranger->fresh());
    $this->getJson('/api/v1/carpool/me')->assertJsonCount(0, 'data.searches')->assertJsonCount(0, 'data.templates');
});

it('un estraneo non conferma, non recensisce e non rimuove la recensione del viaggio altrui', function (): void {
    $ride = cpAccepted($this);
    freezeLocal($this->city, '2026-10-11 12:00');
    flussoCpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => true])->assertOk();
    flussoCpReview($this, $this->passenger, $ride, 'save', ['rating' => 5, 'body' => 'Ottimo viaggio', 'revision' => 0])->assertOk();

    foreach ([carpoolPerson(), $this->driver] as $outsider) {
        flussoCpReview($this, $outsider, $ride, 'save', ['rating' => 1, 'body' => 'Falso', 'revision' => 1])->assertForbidden();
        flussoCpReview($this, $outsider, $ride, 'remove', ['revision' => 1])->assertForbidden();
        flussoCpReview($this, $outsider, $ride, 'confirm', ['confirm' => true])->assertForbidden();
        flussoCpWeb($this, $outsider);
        $this->post(route('carpool.review.action', [$ride, 'remove']), ['request_key' => (string) Str::uuid(), 'revision' => 1])->assertForbidden();
    }
    $review = RideReview::firstOrFail();
    expect($review->rating)->toBe(5)->and($review->body)->toBe('Ottimo viaggio')->and($review->revision)->toBe(1)->and($review->trashed())->toBeFalse();
});
