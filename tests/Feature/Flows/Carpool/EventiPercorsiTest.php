<?php

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\RideRequestStatus;
use App\Enums\RideStatus;
use App\Models\RideFeedback;
use App\Models\RideOffer;
use App\Models\Venue;
use App\Services\Carpool\CarpoolLifecycle;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
 * I passaggi quando cambia l'evento o passa il tempo: evento annullato, spostato d'orario,
 * cambiato di sede, rinviato e poi riprogrammato; la partenza che arriva e il giorno dopo;
 * una data ormai passata. Protegge che nessun accordo sopravviva a un evento diverso da
 * quello per cui era nato e che dopo il viaggio restino feedback e recensione.
 */
require_once __DIR__.'/percorsi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('l\'evento annullato chiude offerte e richieste, avvisa tutti, chiude la chat e la data dei passaggi non si apre più', function (): void {
    $ride = cpAccepted($this);
    $waiting = carpoolPerson();
    $pending = cpRequest($this, $ride->offer, $waiting);
    $chat = percorsoCpChat($ride);

    $this->date->update(['status' => OccurrenceStatus::Cancelled]);

    expect($ride->offer->fresh()->status)->toBe(RideStatus::Cancelled)->and($ride->offer->fresh()->close_reason)->toBe('requirements_or_event_changed')
        ->and($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled)->and($pending->fresh()->status)->toBe(RideRequestStatus::Cancelled)
        ->and($chat->fresh()->read_only_at)->not->toBeNull()
        ->and($this->passenger->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1)
        ->and($waiting->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1)
        ->and($this->driver->notifications()->where('type', 'carpool.cancelled')->count())->toBe(2);

    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.dates', $this->date))->assertNotFound();
    $this->get(route('carpool.create', $this->date))->assertNotFound();
    $this->getJson(route('carpool.index').'?tab=history')->assertJsonPath('data.requests.0.status', 'cancelled');
    $this->getJson(route('carpool.chat', $chat))->assertOk()->assertJsonPath('data.writable', false);
    Sanctum::actingAs($this->driver->fresh());
    $this->getJson('/api/v1/carpool/occurrences/'.$this->date->id)->assertNotFound();
    cpAction($this, $this->driver, 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'return', 'zone' => 'Centro', 'departure_at' => '2026-10-10T23:00:00Z',
        'capacity' => 2, 'accessibility' => 'not_specified', 'driver_declaration' => true])->assertUnprocessable();
});

it('l\'evento spostato di un\'ora annulla gli accordi e il conducente ripubblica nella nuova finestra', function (): void {
    $ride = cpAccepted($this);
    $this->date->update(['starts_at' => CarbonImmutable::parse('2026-10-10 20:00:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-10-10 23:00:00', 'UTC')]);

    expect($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled)->and($ride->offer->fresh()->status)->toBe(RideStatus::Cancelled);
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.dates', $this->date))->assertOk()->assertJsonPath('data.event_date', '10/10/2026 22:00')->assertJsonCount(0, 'data.offers');
    $this->post(route('carpool.action', 'offer'), flussoCpOfferForm($this, ['departure_at' => '2026-10-10T21:00']))->assertSessionHasNoErrors();
    $new = RideOffer::where('status', 'open')->firstOrFail();
    expect($new->departure_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-10 19:00')
        ->and(CarbonImmutable::parse($new->snapshot['starts_at'])->utc()->format('H:i'))->toBe('20:00');

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(1, 'data.offers')->assertJsonPath('data.offers.0.id', $new->id);
    expect(cpRequest($this, $new)->status)->toBe(RideRequestStatus::Pending);
});

it('il cambio di sede dell\'evento annulla gli accordi, il cambio del solo titolo no', function (): void {
    $ride = cpAccepted($this);
    $this->date->event->update(['title' => 'Concerto d\'autunno (nuovo titolo)']);
    expect($ride->fresh()->status)->toBe(RideRequestStatus::Accepted);

    $this->date->event->update(['venue_id' => Venue::factory()->approved()->create(['city_id' => $this->city->id])->id]);
    expect($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled)->and($ride->offer->fresh()->status)->toBe(RideStatus::Cancelled)
        ->and($this->passenger->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1);
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.request', $ride))->assertJsonPath('data.offer.status', 'cancelled')->assertJsonPath('data.ride.can_withdraw', false);
});

it('l\'evento rinviato chiude i passaggi; riprogrammato torna prenotabile solo con offerte nuove', function (): void {
    $ride = cpAccepted($this);
    $this->date->update(['status' => OccurrenceStatus::Postponed]);
    expect($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled);
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertNotFound();

    $this->date->update(['status' => OccurrenceStatus::Scheduled]);
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertOk()->assertJsonCount(0, 'data.offers');
    expect($ride->offer->fresh()->status)->toBe(RideStatus::Cancelled)->and($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled);
    $new = cpOffer($this, data: ['zone' => 'Arcella']);
    expect(cpRequest($this, $new)->status)->toBe(RideRequestStatus::Pending);
});

it('alla partenza le richieste in attesa scadono, le confermate restano e il giorno dopo il viaggio si chiude con feedback e recensione', function (): void {
    $ride = cpAccepted($this);
    $waiting = carpoolPerson();
    $pending = cpRequest($this, $ride->offer, $waiting);

    freezeLocal($this->city, '2026-10-10 20:30');
    app(CarpoolLifecycle::class)->tick();
    expect($pending->fresh()->status)->toBe(RideRequestStatus::Expired)->and($ride->fresh()->status)->toBe(RideRequestStatus::Accepted)
        ->and($ride->offer->fresh()->status)->toBe(RideStatus::Open);
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.request', $ride))->assertJsonPath('data.ride.can_feedback', true)->assertJsonPath('data.ride.can_withdraw', false)
        ->assertJsonPath('data.ride.can_review', true);
    $this->getJson(route('carpool.chat', percorsoCpChat($ride)))->assertJsonPath('data.writable', true);

    freezeLocal($this->city, '2026-10-11 21:00');
    app(CarpoolLifecycle::class)->tick();
    expect($ride->offer->fresh()->status)->toBe(RideStatus::Completed);
    cpDiscovery($this, $this->passenger, 'feedback', ['request_id' => $ride->id, 'kind' => 'travelled'])->assertOk();
    flussoCpReview($this, $this->passenger, $ride, 'confirm', ['confirm' => true])->assertOk();
    flussoCpReview($this, $this->passenger, $ride, 'save', ['rating' => 5, 'revision' => 0])->assertOk();
    expect(RideFeedback::count())->toBe(1);
    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.request', $ride))->assertOk()->assertDontSee('data-request-withdraw', false);
});

it('a evento finito la data non accetta più offerte, ricerche né richieste', function (): void {
    $offer = cpOffer($this, data: ['leg' => 'return', 'departure_at' => '2026-10-10T23:00:00Z']);
    freezeLocal($this->city, '2026-10-12 12:00');

    cpAction($this, carpoolPerson(), 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'return', 'zone' => 'Centro', 'departure_at' => '2026-10-12T18:00:00Z',
        'capacity' => 2, 'accessibility' => 'not_specified', 'driver_declaration' => true])->assertUnprocessable();
    cpDiscovery($this, $this->passenger, 'search', percorsoCpSearch($this, ['leg' => 'return', 'earliest_at' => '2026-10-12T17:00:00Z', 'latest_at' => '2026-10-12T19:00:00Z']))
        ->assertUnprocessable();
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertNotFound();
    cpAction($this, $this->driver, 'cancel', ['offer_id' => $offer->id])->assertConflict();
    expect(RideOffer::count())->toBe(1)->and($offer->fresh()->status)->toBe(RideStatus::Open);
});
