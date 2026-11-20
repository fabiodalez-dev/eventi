<?php

declare(strict_types=1);

use App\Enums\RideRequestStatus;
use App\Models\RideConversation;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Services\Carpool\CarpoolLifecycle;
use App\Services\Carpool\CarpoolService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * La richiesta di un posto vista da chi la fa e da chi la riceve: ripensamenti, ultimo posto
 * conteso, gruppi con tappa, doppio clic, decisioni che si incrociano, revisioni superate,
 * tetti alle richieste in attesa. Protegge che posti, stati e avvisi restino coerenti anche
 * quando le due persone agiscono una dopo l'altra sulla stessa offerta.
 */
require_once __DIR__.'/percorsi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('il passeggero ritira la richiesta in attesa e la ripresenta dal sito; dopo il secondo ritiro il modulo non torna più', function (): void {
    $offer = cpOffer($this);
    percorsoCpPost($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertSessionHasNoErrors();
    $first = RideRequest::firstOrFail();
    $this->post(route('carpool.action', 'withdraw'), ['request_key' => (string) Str::uuid(), 'request_id' => $first->id])->assertRedirect(route('carpool.request', $first));
    expect($first->fresh()->status)->toBe(RideRequestStatus::Withdrawn)->and($this->driver->notifications()->where('type', 'carpool.withdrawn')->count())->toBe(1);

    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee('data-offer-request-form', false)->assertDontSee('data-offer-own-request', false);
    $this->post(route('carpool.action', 'request'), ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertSessionHasNoErrors();
    $second = RideRequest::whereKeyNot($first->id)->firstOrFail();
    expect($second->status)->toBe(RideRequestStatus::Pending);
    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee('data-offer-own-request', false)->assertDontSee('data-offer-request-form', false);

    $this->post(route('carpool.action', 'withdraw'), ['request_key' => (string) Str::uuid(), 'request_id' => $second->id])->assertRedirect(route('carpool.request', $second));
    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee('data-offer-own-request', false)->assertSee(__('carpool.RideRequestStatus.withdrawn'))
        ->assertDontSee('data-offer-request-form', false);
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertConflict()
        ->assertJsonPath('error.message', __('carpool.errors.withdrawals'));
    expect(RideRequest::count())->toBe(2);
});

it('due passeggeri chiedono l\'ultimo posto: il primo confermato lo prende, l\'altro resta in attesa e scade alla partenza con un avviso', function (): void {
    $offer = cpOffer($this, data: ['capacity' => 1]);
    $winner = cpRequest($this, $offer);
    $loser = carpoolPerson();
    $late = cpRequest($this, $offer, $loser);

    percorsoCpPost($this, $this->driver, 'accept', ['request_id' => $winner->id])->assertRedirect(route('carpool.offer', $offer));
    // Il rifiuto torna all'offerta e il messaggio compare nel riepilogo degli errori della pagina.
    $this->followingRedirects()->from(route('carpool.offer', $offer))->post(route('carpool.action', 'accept'), ['request_key' => (string) Str::uuid(), 'request_id' => $late->id])
        ->assertOk()->assertSee(__('carpool.errors.capacity'))->assertSee('data-offer-confirmed', false);
    expect($late->fresh()->status)->toBe(RideRequestStatus::Pending)->and(app(CarpoolService::class)->available($offer))->toBe(0);
    $this->getJson(route('carpool.summary'))->assertJsonPath('data.pending', 1);

    freezeLocal($this->city, '2026-10-10 20:30');
    app(CarpoolLifecycle::class)->tick();
    expect($late->fresh()->status)->toBe(RideRequestStatus::Expired)->and($late->fresh()->close_reason)->toBe('departure_reached')
        ->and($winner->fresh()->status)->toBe(RideRequestStatus::Accepted)
        ->and($loser->notifications()->where('type', 'carpool.expired')->count())->toBe(1)
        ->and($this->passenger->notifications()->where('type', 'carpool.expired')->count())->toBe(0);
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.summary'))->assertJsonPath('data.pending', 0);
    flussoCpWeb($this, $loser);
    $this->getJson(route('carpool.index').'?tab=history')->assertJsonPath('data.requests.0.status', 'expired');
    $this->get(route('carpool.request', $late))->assertOk()->assertSee(mb_strtolower(__('carpool.RideRequestStatus.expired')));
});

it('il conducente accetta dal sito una richiesta di gruppo con tappa e il passeggero ritrova posti, tappa e chat', function (): void {
    $offer = cpOffer($this, data: ['stops' => ['Stazione']]);
    percorsoCpPost($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 3, 'companions_adult' => '1', 'stop_index' => 0,
        'note' => 'Siamo in tre con due zaini'])->assertSessionHasNoErrors();
    $ride = RideRequest::firstOrFail();

    flussoCpWeb($this, $this->driver);
    $this->get(route('carpool.request', $ride))->assertOk()->assertSee('Siamo in tre con due zaini')->assertSee(route('carpool.action', 'accept'), false);
    $this->post(route('carpool.action', 'accept'), ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id])->assertRedirect(route('carpool.offer', $offer));
    $this->getJson(route('carpool.offer', $offer))->assertJsonPath('data.offer.available', 0)->assertJsonPath('data.requests.0.status', 'accepted');

    $chat = percorsoCpChat($ride);
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.index').'?tab=requested')->assertJsonPath('data.requests.0.seats', 3)->assertJsonPath('data.requests.0.companions', 2)
        ->assertJsonPath('data.requests.0.stop_index', 0)->assertJsonPath('data.requests.0.chat_id', $chat->id);
    $this->get(route('carpool.request', $ride))->assertOk()->assertSee(route('carpool.chat', $chat), false)->assertSee('data-request-reduce', false);
    $this->get(route('carpool.chat', $chat))->assertOk();
    expect($this->passenger->notifications()->where('type', 'carpool.accepted')->count())->toBe(1);
});

it('il doppio clic su «Richiedi i posti» crea una sola richiesta, e la stessa chiave con dati diversi è respinta', function (): void {
    $offer = cpOffer($this);
    $key = (string) Str::uuid();
    flussoCpWeb($this, $this->passenger);
    $form = ['request_key' => $key, 'offer_id' => $offer->id, 'revision' => 1, 'seats' => 1];

    $this->post(route('carpool.action', 'request'), $form)->assertSessionHasNoErrors();
    $ride = RideRequest::firstOrFail();
    $this->post(route('carpool.action', 'request'), $form)->assertRedirect(route('carpool.request', $ride))->assertSessionHasNoErrors();
    expect(RideRequest::count())->toBe(1)->and($this->driver->notifications()->where('type', 'carpool.requested')->count())->toBe(1);

    $this->from(route('carpool.offer', $offer))->post(route('carpool.action', 'request'), [...$form, 'seats' => 2, 'companions_adult' => '1'])
        ->assertRedirect(route('carpool.offer', $offer))->assertSessionHasErrors(['form' => __('carpool.errors.retry')]);
    expect(RideRequest::count())->toBe(1)->and($ride->fresh()->seats)->toBe(1);
});

it('il conducente annulla mentre il passeggero sta per rinunciare: la rinuncia non cambia nulla e l\'accettazione tardiva è respinta', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer);

    cpAction($this, $this->driver, 'cancel', ['offer_id' => $offer->id])->assertOk();
    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $ride->id])->assertOk()->assertJsonPath('data.id', $ride->id);
    cpAction($this, $this->driver, 'accept', ['request_id' => $ride->id])->assertConflict();

    expect($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled)->and($ride->fresh()->close_reason)->toBe('driver_cancelled')
        ->and($this->passenger->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1)
        ->and($this->driver->notifications()->where('type', 'carpool.withdrawn')->count())->toBe(0)
        ->and(RideConversation::count())->toBe(0);
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.request', $ride))->assertJsonPath('data.ride.can_withdraw', false)->assertJsonPath('data.offer.status', 'cancelled');
});

it('chi chiede con la revisione superata torna all\'offerta con il messaggio e trova le nuove condizioni nel modulo', function (): void {
    $offer = cpOffer($this);
    cpAction($this, $this->driver, 'update', ['offer_id' => $offer->id, 'revision' => 1, 'zone' => 'Arcella', 'departure_at' => '2026-10-10T18:00:00Z',
        'capacity' => 3, 'accessibility' => 'not_specified'])->assertOk();

    flussoCpWeb($this, $this->passenger);
    $this->from(route('carpool.offer', $offer))->post(route('carpool.action', 'request'), ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])
        ->assertRedirect(route('carpool.offer', $offer))->assertSessionHasErrors(['form' => __('carpool.errors.changed')]);
    $this->followingRedirects()->from(route('carpool.offer', $offer))
        ->post(route('carpool.action', 'request'), ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])
        ->assertOk()->assertSee(__('carpool.errors.changed'))->assertSee('Arcella')->assertSee('name="revision" value="2"', false);
    expect(RideRequest::count())->toBe(0);

    $this->post(route('carpool.action', 'request'), ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id, 'revision' => 2, 'seats' => 1])->assertSessionHasNoErrors();
    expect(RideRequest::firstOrFail()->offer_revision)->toBe(2);
});

it('con tre richieste in attesa sull\'andata la quarta è respinta finché una non viene ritirata, mentre il ritorno resta libero', function (): void {
    $offers = collect(['Arcella', 'Stazione', 'Guizza', 'Forcellini'])->map(fn (string $zone) => cpOffer($this, carpoolPerson(), ['zone' => $zone]));
    $rides = $offers->take(3)->map(fn (RideOffer $offer) => cpRequest($this, $offer));

    cpAction($this, $this->passenger, 'request', ['offer_id' => $offers[3]->id, 'revision' => 1, 'seats' => 1])->assertUnprocessable()
        ->assertJsonPath('error.message', __('carpool.errors.limit'));
    $back = cpOffer($this, carpoolPerson(), ['leg' => 'return', 'zone' => 'Centro', 'departure_at' => '2026-10-10T23:00:00Z']);
    expect(cpRequest($this, $back)->status)->toBe(RideRequestStatus::Pending);

    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $rides[0]->id])->assertOk();
    $fourth = cpRequest($this, $offers[3]);
    expect($fourth->status)->toBe(RideRequestStatus::Pending)
        ->and(RideRequest::where('user_id', $this->passenger->id)->where('status', 'pending')->count())->toBe(4);
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.index').'?tab=requested')->assertJsonCount(4, 'data.requests');
});

it('rifiutato da un conducente il passeggero non può insistere, chiede a un altro e viene accettato', function (): void {
    $first = cpOffer($this);
    $refused = cpRequest($this, $first);
    percorsoCpPost($this, $this->driver, 'decline', ['request_id' => $refused->id])->assertRedirect(route('carpool.offer', $first));

    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.offer', $first))->assertOk()->assertSee('data-offer-own-request', false)->assertSee(__('carpool.RideRequestStatus.declined'))
        ->assertDontSee('data-offer-request-form', false);
    $this->from(route('carpool.offer', $first))->post(route('carpool.action', 'request'), ['request_key' => (string) Str::uuid(), 'offer_id' => $first->id, 'revision' => 1, 'seats' => 1])
        ->assertSessionHasErrors(['form' => __('carpool.errors.declined')]);

    $otherDriver = carpoolPerson();
    $second = cpOffer($this, $otherDriver, ['zone' => 'Arcella']);
    $ride = cpRequest($this, $second);
    percorsoCpPost($this, $otherDriver, 'accept', ['request_id' => $ride->id])->assertRedirect(route('carpool.offer', $second));
    expect($ride->fresh()->status)->toBe(RideRequestStatus::Accepted)->and($refused->fresh()->status)->toBe(RideRequestStatus::Declined)
        ->and(RideRequest::where('user_id', $this->passenger->id)->count())->toBe(2);
});

it('nella pagina dell\'offerta il passeggero vede solo la propria richiesta, il conducente tutte con le note', function (): void {
    $offer = cpOffer($this);
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1, 'note' => 'Nota di Anna'])->assertOk();
    $other = carpoolPerson();
    cpAction($this, $other, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1, 'note' => 'Nota di Bruno'])->assertOk();

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.offer', $offer))->assertJsonCount(1, 'data.requests')->assertJsonPath('data.requests.0.note', 'Nota di Anna');
    $this->get(route('carpool.offer', $offer))->assertOk()->assertDontSee('Nota di Bruno');
    flussoCpWeb($this, $other);
    $this->getJson(route('carpool.offer', $offer))->assertJsonCount(1, 'data.requests')->assertJsonPath('data.requests.0.note', 'Nota di Bruno');
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.offer', $offer))->assertJsonCount(2, 'data.requests');
    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee('Nota di Anna')->assertSee('Nota di Bruno');
});

it('chi ha il posto confermato all\'andata offre il ritorno e accetta a sua volta il proprio conducente', function (): void {
    $outbound = cpAccepted($this);
    $back = cpOffer($this, $this->passenger, ['leg' => 'return', 'zone' => 'Centro', 'departure_at' => '2026-10-10T23:00:00Z']);
    $return = cpRequest($this, $back, $this->driver);
    cpAction($this, $this->passenger, 'accept', ['request_id' => $return->id])->assertOk();

    expect(RideConversation::count())->toBe(2)->and($return->fresh()->status)->toBe(RideRequestStatus::Accepted)
        ->and($outbound->fresh()->status)->toBe(RideRequestStatus::Accepted);
    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/me')->assertJsonCount(1, 'data.offers')->assertJsonPath('data.offers.0.leg', 'return')
        ->assertJsonCount(1, 'data.requests')->assertJsonPath('data.requests.0.offer.leg', 'outbound');
    $this->getJson('/api/v1/carpool/chats')->assertJsonCount(2, 'data.chats');
    // Né l'uno né l'altro possono aggiungere una seconda corsa sulla tratta che occupano già.
    cpAction($this, $this->driver, 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'return', 'zone' => 'Altrove', 'departure_at' => '2026-10-10T23:30:00Z',
        'capacity' => 2, 'accessibility' => 'not_specified', 'driver_declaration' => true])->assertConflict()->assertJsonPath('error.message', __('carpool.errors.occupied'));
});

it('il passeggero riduce i posti dal sito e quelli liberati vanno a un altro gruppo', function (): void {
    $ride = cpAccepted($this, 3);
    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.request', $ride))->assertOk()->assertSee('data-request-reduce', false);
    $this->post(route('carpool.action', 'reduce'), ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id, 'seats' => 1])->assertRedirect(route('carpool.request', $ride));
    expect($ride->fresh()->seats)->toBe(1)->and($this->driver->notifications()->where('type', 'carpool.reduced')->count())->toBe(1);
    $this->get(route('carpool.request', $ride))->assertOk()->assertDontSee('data-request-reduce', false);

    $group = carpoolPerson();
    $other = cpRequest($this, $ride->offer->fresh(), $group, 2);
    percorsoCpPost($this, $this->driver, 'accept', ['request_id' => $other->id])->assertSessionHasNoErrors();
    expect($other->fresh()->status)->toBe(RideRequestStatus::Accepted)->and(app(CarpoolService::class)->available($ride->offer))->toBe(0);
});
