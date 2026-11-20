<?php

declare(strict_types=1);

use App\Enums\RideRequestStatus;
use App\Enums\RideStatus;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideSearch;
use App\Models\UserBlock;
use App\Services\Carpool\CommunitySafety;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
 * Sospensioni decise dallo staff e blocchi fra persone, mentre ci sono passaggi in corso:
 * accordi futuri chiusi con avviso all'altra parte, ricerche spente, pagine che spiegano il
 * motivo, ripartenza dopo la revoca, tetto ai cicli blocca-sblocca. Protegge che nessuno
 * resti legato a un accordo con chi non può più viaggiare con lui.
 */
require_once __DIR__.'/percorsi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('la sospensione dai passaggi chiude gli accordi futuri del passeggero, avvisa i conducenti, spegne le ricerche e la pagina dice il motivo', function (): void {
    $ride = cpAccepted($this);
    $back = cpOffer($this, carpoolPerson(), ['leg' => 'return', 'zone' => 'Centro', 'departure_at' => '2026-10-10T23:00:00Z']);
    $pending = cpRequest($this, $back);
    $search = cpDiscovery($this, $this->passenger, 'search', percorsoCpSearch($this, ['occurrence_id' => occurrenceAt($this->city, $this->category, '2026-10-17 19:00:00')->id,
        'earliest_at' => '2026-10-17T17:00:00Z', 'latest_at' => '2026-10-17T19:00:00Z']))->assertOk()->json('data.id');

    app(CommunitySafety::class)->restrict(cpStaff(), $this->passenger, 'carpool', true, 'Segnalazioni ripetute verificate');

    expect($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled)->and($pending->fresh()->status)->toBe(RideRequestStatus::Cancelled)
        ->and(RideSearch::findOrFail($search)->active)->toBeFalse()
        ->and($this->driver->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1)
        ->and($back->driver->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1)
        ->and($this->passenger->notifications()->where('type', 'carpool.moderation')->count())->toBe(1);
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonPath('data.access.reason', 'suspended')->assertJsonPath('data.offers', []);
    // «I miei passaggi» dice il motivo vero e porta all'assistenza, non a completare verifiche.
    $this->get(route('carpool.index'))->assertOk()->assertSee('data-carpool-blocked="suspended"', false)->assertSee(__('carpool.profile_blocked.suspended'))
        ->assertDontSee(__('carpool.onboarding'));
    $this->getJson(route('carpool.index').'?tab=history')->assertJsonCount(2, 'data.requests')->assertJsonPath('data.requests.0.offer.zone', null);
    cpAction($this, $this->passenger, 'request', ['offer_id' => $ride->ride_offer_id, 'revision' => 1, 'seats' => 1])->assertForbidden();
});

it('il conducente sospeso vede annullata l\'offerta e non ne pubblica di nuove; tolta la sospensione riparte con un\'offerta nuova', function (): void {
    $ride = cpAccepted($this);
    $admin = cpStaff();
    app(CommunitySafety::class)->restrict($admin, $this->driver, 'carpool', true, 'Richieste di denaro accertate');

    $offer = $ride->offer->fresh();
    expect($offer->status)->toBe(RideStatus::Cancelled)->and($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled)
        ->and($this->passenger->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1);
    cpAction($this, $this->driver, 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'zone' => 'Arcella', 'departure_at' => '2026-10-10T18:00:00Z',
        'capacity' => 3, 'accessibility' => 'not_specified', 'driver_declaration' => true])->assertForbidden();
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(0, 'data.offers');

    app(CommunitySafety::class)->restrict($admin, $this->driver, 'carpool', false, 'Chiarimento ricevuto');
    $new = cpOffer($this, data: ['zone' => 'Arcella']);
    expect($offer->fresh()->status)->toBe(RideStatus::Cancelled)->and($new->status)->toBe(RideStatus::Open);
    $again = cpRequest($this, $new);
    expect($again->status)->toBe(RideRequestStatus::Pending)->and(RideRequest::count())->toBe(2);
});

it('il passeggero blocca il conducente: la richiesta in attesa si chiude e ciascuno sparisce dagli elenchi dell\'altro', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer);
    cpDiscovery($this, $this->passenger, 'search', percorsoCpSearch($this, ['leg' => 'return', 'is_public' => true, 'earliest_at' => '2026-10-10T22:30:00Z', 'latest_at' => '2026-10-10T23:30:00Z']))->assertOk();
    UserBlock::create(['user_id' => $this->passenger->id, 'blocked_user_id' => $this->driver->id]);

    expect($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled)->and($ride->fresh()->close_reason)->toBe('requirements_or_block_changed')
        ->and($this->driver->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1);
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(0, 'data.wanted');
    $this->getJson(route('carpool.offer', $offer))->assertJsonPath('data.requests.0.status', 'cancelled');
    $this->getJson(route('carpool.summary'))->assertJsonPath('data.pending', 0);
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(0, 'data.offers');
    $this->get(route('carpool.offer', $offer))->assertForbidden();
});

it('dopo uno sblocco il passeggero richiede di nuovo, ma i cicli blocca e sblocca consumano i tentativi', function (): void {
    $offer = cpOffer($this);
    cpRequest($this, $offer);
    foreach ([1, 2] as $round) {
        $block = UserBlock::create(['user_id' => $this->passenger->id, 'blocked_user_id' => $this->driver->id]);
        $block->delete();
        if ($round === 1) {
            expect(cpRequest($this, $offer->fresh())->status)->toBe(RideRequestStatus::Pending);
        }
    }

    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertConflict()
        ->assertJsonPath('error.message', __('carpool.errors.withdrawals'));
    expect(RideRequest::where('user_id', $this->passenger->id)->where('close_reason', 'requirements_or_block_changed')->count())->toBe(2)
        ->and(RideRequest::where('active_key', '!=', null)->count())->toBe(0);
    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.offer', $offer))->assertOk()->assertDontSee('data-offer-request-form', false);
});

it('chi è sospeso da tutta la community perde passaggi, chat e recensioni, ma ritrova la propria cronologia', function (): void {
    $ride = cpAccepted($this);
    $chat = percorsoCpChat($ride);
    $other = cpOffer($this, carpoolPerson(), ['leg' => 'return', 'zone' => 'Centro', 'departure_at' => '2026-10-10T23:00:00Z']);
    $this->passenger->forceFill(['community_suspended_at' => now()])->save();

    expect($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled);
    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.chat', $chat))->assertForbidden();
    $this->get(route('carpool.reviews', $this->driver))->assertForbidden();
    $this->get(route('carpool.offer', $other))->assertForbidden();
    $this->getJson(route('carpool.index').'?tab=history')->assertJsonCount(1, 'data.requests')->assertJsonPath('data.requests.0.id', $ride->id);
    $this->get(route('carpool.request', $ride))->assertOk();
    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/requirements')->assertJsonPath('data.access.reason', 'suspended');
    expect(RideOffer::where('status', 'open')->count())->toBe(2);
});
