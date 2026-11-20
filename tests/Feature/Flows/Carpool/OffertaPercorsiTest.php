<?php

declare(strict_types=1);

use App\Enums\RideRequestStatus;
use App\Enums\RideStatus;
use App\Models\CarpoolAudit;
use App\Models\RideConversation;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideTemplate;
use App\Services\Carpool\CarpoolLifecycle;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Il conducente e la sua offerta, dall'inizio alla fine: bozza, modifica dal modulo della
 * pagina, chiusura e riapertura, annullamento e nuova offerta, modelli su un'altra data,
 * tappe, viaggio concluso. Protegge che ogni passo del sito lasci l'offerta, le richieste e
 * la tratta occupata nello stato giusto.
 */
require_once __DIR__.'/percorsi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('il conducente salva una bozza dal sito, la corregge e la pubblica: solo allora la vedono i passeggeri', function (): void {
    $response = percorsoCpPost($this, $this->driver, 'offer', flussoCpOfferForm($this, ['draft' => '1']));
    $offer = RideOffer::firstOrFail();
    $response->assertRedirect(route('carpool.offer', $offer));
    expect($offer->status)->toBe(RideStatus::Draft)->and(DB::table('ride_occupancies')->count())->toBe(0);
    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee(__('carpool.publish'))->assertSee('data-offer-update', false);

    percorsoCpPost($this, $this->driver, 'update', percorsoCpUpdateForm($offer, ['zone' => 'Arcella', 'departure_at' => '2026-10-10T19:30']))
        ->assertRedirect(route('carpool.offer', $offer))->assertSessionHasNoErrors();
    expect($offer->fresh()->zone)->toBe('Arcella')->and($offer->fresh()->revision)->toBe(2)->and($offer->fresh()->status)->toBe(RideStatus::Draft);

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(0, 'data.offers');
    $this->get(route('carpool.offer', $offer))->assertForbidden();

    percorsoCpPost($this, $this->driver, 'publish', ['offer_id' => $offer->id, 'driver_declaration' => '1'])->assertRedirect(route('carpool.offer', $offer));
    expect($offer->fresh()->status)->toBe(RideStatus::Open)
        ->and(DB::table('ride_occupancies')->where('user_id', $this->driver->id)->where('ride_offer_id', $offer->id)->count())->toBe(1);

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(1, 'data.offers')->assertJsonPath('data.offers.0.zone', 'Arcella')
        ->assertJsonPath('data.offers.0.revision', 2)->assertJsonPath('data.offers.0.departure_local', '2026-10-10T19:30');
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertConflict()
        ->assertJsonPath('error.message', __('carpool.errors.changed'));
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 2, 'seats' => 1])->assertOk();
});

it('con un passeggero confermato il conducente aumenta i posti dal modulo di modifica, ma non sposta la partenza né scende sotto i posti presi', function (): void {
    $ride = cpAccepted($this, 2);
    $offer = $ride->offer;
    flussoCpWeb($this, $this->driver);
    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee('data-offer-update', false)->assertSee('data-offer-confirmed', false);

    percorsoCpPost($this, $this->driver, 'update', percorsoCpUpdateForm($offer, ['capacity' => 5]))->assertRedirect(route('carpool.offer', $offer))->assertSessionHasNoErrors();
    expect($offer->fresh()->capacity)->toBe(5)->and($offer->fresh()->revision)->toBe(1)->and($ride->fresh()->status)->toBe(RideRequestStatus::Accepted);

    $this->from(route('carpool.offer', $offer))->post(route('carpool.action', 'update'), percorsoCpUpdateForm($offer, ['departure_at' => '2026-10-10T20:30']))
        ->assertRedirect(route('carpool.offer', $offer))->assertSessionHasErrors(['form' => __('carpool.errors.agreement')]);
    $this->from(route('carpool.offer', $offer))->post(route('carpool.action', 'update'), percorsoCpUpdateForm($offer, ['capacity' => 1]))
        ->assertRedirect(route('carpool.offer', $offer))->assertSessionHasErrors(['capacity' => __('carpool.errors.capacity')]);

    $offer->refresh();
    expect($offer->capacity)->toBe(5)->and($offer->departure_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-10 18:00')
        ->and($ride->fresh()->status)->toBe(RideRequestStatus::Accepted)
        ->and($this->passenger->notifications()->where('type', 'carpool.cancelled')->count())->toBe(0);
    $this->getJson(route('carpool.offer', $offer))->assertJsonPath('data.offer.available', 3);
});

it('chiudere alle nuove richieste non tocca chi è già in attesa: il conducente lo accetta e poi riapre', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer);
    $late = carpoolPerson();

    percorsoCpPost($this, $this->driver, 'close', ['offer_id' => $offer->id])->assertRedirect(route('carpool.offer', $offer));
    expect($offer->fresh()->status)->toBe(RideStatus::Closed);
    cpAction($this, $late, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertNotFound();
    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee('data-offer-own-request', false);

    percorsoCpPost($this, $this->driver, 'accept', ['request_id' => $ride->id])->assertRedirect(route('carpool.offer', $offer));
    expect($ride->fresh()->status)->toBe(RideRequestStatus::Accepted)->and(RideConversation::where('ride_request_id', $ride->id)->exists())->toBeTrue();
    $this->getJson(route('carpool.offer', $offer))->assertJsonPath('data.offer.status', 'closed')->assertJsonPath('data.offer.available', 2);

    percorsoCpPost($this, $this->driver, 'reopen', ['offer_id' => $offer->id])->assertRedirect(route('carpool.offer', $offer));
    $other = cpRequest($this, $offer->fresh(), $late);
    expect($offer->fresh()->status)->toBe(RideStatus::Open)->and($other->status)->toBe(RideRequestStatus::Pending);
});

it('annullare un\'offerta libera la tratta: il conducente ne pubblica subito un\'altra e la vecchia richiesta resta nello storico', function (): void {
    $first = cpOffer($this);
    $old = cpRequest($this, $first);

    percorsoCpPost($this, $this->driver, 'cancel', ['offer_id' => $first->id])->assertRedirect(route('carpool.offer', $first));
    percorsoCpPost($this, $this->driver, 'offer', flussoCpOfferForm($this, ['zone' => 'Arcella']))->assertSessionHasNoErrors();
    $second = RideOffer::where('zone', 'Arcella')->firstOrFail();
    expect($second->status)->toBe(RideStatus::Open)
        ->and(DB::table('ride_occupancies')->where('user_id', $this->driver->id)->pluck('ride_offer_id')->all())->toBe([$second->id])
        ->and($old->fresh()->status)->toBe(RideRequestStatus::Cancelled);

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(1, 'data.offers')->assertJsonPath('data.offers.0.id', $second->id);
    $this->getJson(route('carpool.offer', $first))->assertOk()->assertJsonPath('data.offer.status', 'cancelled')->assertJsonPath('data.offer.can_request', false);

    $new = cpRequest($this, $second);
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.index').'?tab=requested')->assertJsonCount(1, 'data.requests')->assertJsonPath('data.requests.0.id', $new->id);
    $this->getJson(route('carpool.index').'?tab=history')->assertJsonCount(1, 'data.requests')->assertJsonPath('data.requests.0.id', $old->id);
});

it('il conducente ripete il passaggio su un\'altra data partendo dal modello salvato', function (): void {
    $saturday = occurrenceAt($this->city, $this->category, '2026-10-17 19:00:00', '2026-10-17 22:00:00');
    $offer = cpOffer($this, data: ['zone' => 'Arcella', 'capacity' => 2, 'accessibility' => 'folding_chair', 'stops' => ['Stazione'], 'note' => 'Parto dal parcheggio']);
    flussoCpWeb($this, $this->driver);
    $this->post(route('carpool.discovery', 'template'), ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id, 'name' => 'Sabato sera'])->assertRedirect(route('carpool.index'));

    $settings = $this->getJson(route('carpool.create', $saturday))->assertOk()->assertJsonPath('data.templates.0.name', 'Sabato sera')->json('data.templates.0.settings');
    $this->post(route('carpool.action', 'offer'), flussoCpOfferForm($this, [...$settings, 'occurrence_id' => $saturday->id, 'departure_at' => '2026-10-17T20:00']))
        ->assertSessionHasNoErrors();

    $copy = RideOffer::where('occurrence_id', $saturday->id)->firstOrFail();
    expect($copy->zone)->toBe('Arcella')->and($copy->capacity)->toBe(2)->and($copy->accessibility->value)->toBe('folding_chair')
        ->and($copy->stops)->toBe(['Stazione'])->and($copy->note)->toBe('Parto dal parcheggio')->and($copy->status)->toBe(RideStatus::Open)
        ->and(DB::table('ride_occupancies')->where('user_id', $this->driver->id)->count())->toBe(2)
        ->and(RideTemplate::count())->toBe(1);

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $saturday))->assertJsonCount(1, 'data.offers')->assertJsonPath('data.offers.0.id', $copy->id);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(1, 'data.offers')->assertJsonPath('data.offers.0.id', $offer->id);
});

it('a viaggio concluso l\'offerta non si modifica né si annulla e resta nello storico di conducente e passeggero', function (): void {
    $ride = cpAccepted($this);
    $offer = $ride->offer;
    freezeLocal($this->city, '2026-10-11 21:00');
    app(CarpoolLifecycle::class)->tick();

    expect($offer->fresh()->status)->toBe(RideStatus::Completed)->and(percorsoCpChat($ride)->read_only_at)->not->toBeNull()
        ->and(DB::table('ride_occupancies')->count())->toBe(0);
    cpAction($this, $this->driver, 'update', ['offer_id' => $offer->id, 'revision' => 1, 'zone' => 'Altrove', 'departure_at' => '2026-10-10T18:00:00Z',
        'capacity' => 3, 'accessibility' => 'not_specified'])->assertConflict()->assertJsonPath('error.message', __('carpool.errors.closed'));
    cpAction($this, $this->driver, 'cancel', ['offer_id' => $offer->id])->assertConflict()->assertJsonPath('error.message', __('carpool.errors.departed'));
    cpAction($this, $this->driver, 'close', ['offer_id' => $offer->id])->assertConflict();
    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $ride->id])->assertConflict()->assertJsonPath('error.message', __('carpool.errors.departed'));

    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.index').'?tab=offered')->assertJsonCount(0, 'data.offers');
    $this->getJson(route('carpool.index').'?tab=history')->assertJsonCount(1, 'data.offers')->assertJsonPath('data.offers.0.status', 'completed');
    $this->get(route('carpool.index').'?tab=history')->assertOk()->assertSee(__('carpool.RideStatus.completed'));
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.index').'?tab=requested')->assertJsonCount(0, 'data.requests');
    $this->getJson(route('carpool.index').'?tab=history')->assertJsonCount(1, 'data.requests')->assertJsonPath('data.requests.0.status', 'accepted')
        ->assertJsonPath('data.requests.0.can_withdraw', false);
    expect($offer->fresh()->zone)->toBe('Prato della Valle')->and($ride->fresh()->status)->toBe(RideRequestStatus::Accepted);
});

it('spostata la partenza, la richiesta in attesa decade e il passeggero avvisato richiede di nuovo dalla pagina dell\'offerta', function (): void {
    $offer = cpOffer($this);
    percorsoCpPost($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertSessionHasNoErrors();
    $first = RideRequest::firstOrFail();

    percorsoCpPost($this, $this->driver, 'update', percorsoCpUpdateForm($offer, ['departure_at' => '2026-10-10T19:30']))->assertSessionHasNoErrors();
    expect($first->fresh()->status)->toBe(RideRequestStatus::Cancelled)->and($first->fresh()->close_reason)->toBe('offer_changed')
        ->and($this->passenger->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1);

    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee('data-offer-request-form', false)
        ->assertSee('name="revision" value="2"', false);
    $this->post(route('carpool.action', 'request'), ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id, 'revision' => 2, 'seats' => 1])
        ->assertSessionHasNoErrors();
    $second = RideRequest::whereKeyNot($first->id)->firstOrFail();
    expect($second->status)->toBe(RideRequestStatus::Pending)->and($second->offer_revision)->toBe(2);
    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee('data-offer-own-request', false)->assertDontSee('data-offer-request-form', false);
});

it('una nuova partenza fuori dalla finestra della tratta è respinta in modifica e il modulo lo dice sotto la partenza', function (): void {
    $offer = cpOffer($this);
    flussoCpWeb($this, $this->driver);

    $this->from(route('carpool.offer', $offer))->post(route('carpool.action', 'update'), percorsoCpUpdateForm($offer, ['departure_at' => '2026-10-15T20:00', 'zone' => 'Arcella']))
        ->assertRedirect(route('carpool.offer', $offer))->assertSessionHasErrors(['departure_at' => __('carpool.errors.departure')])->assertSessionHasInput('zone', 'Arcella');
    // Seguendo il ritorno, come il browser, il modulo di modifica è aperto con il messaggio sotto la partenza.
    $this->followingRedirects()->from(route('carpool.offer', $offer))->post(route('carpool.action', 'update'), percorsoCpUpdateForm($offer, ['departure_at' => '2026-10-15T20:00', 'zone' => 'Arcella']))
        ->assertOk()->assertSee('id="modifica-partenza-errore"', false)->assertSee(__('carpool.errors.departure'))->assertSee('value="Arcella"', false);
    cpAction($this, $this->driver, 'update', ['offer_id' => $offer->id, 'revision' => 1, 'zone' => 'Arcella', 'departure_at' => '2026-09-30T18:00:00Z',
        'capacity' => 3, 'accessibility' => 'not_specified'])->assertUnprocessable()->assertJsonPath('error.message', __('carpool.errors.departure'));

    expect($offer->fresh()->zone)->toBe('Prato della Valle')->and($offer->fresh()->revision)->toBe(1)
        ->and(CarpoolAudit::where('action', 'ride_updated')->count())->toBe(0);
});

it('l\'offerta con tre tappe si trova cercando una tappa e il passeggero chiede di salire alla seconda', function (): void {
    $offer = cpOffer($this, data: ['stops' => ['Stazione', 'Arcella', 'Ospedale']]);
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date).'?zone=arcella')->assertJsonCount(1, 'data.offers')->assertJsonPath('data.offers.0.stops.1', 'Arcella');
    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee(__('carpool.stop_select'))->assertSee('Ospedale');

    $this->post(route('carpool.action', 'request'), ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id, 'revision' => 1, 'seats' => 1, 'stop_index' => 1])
        ->assertSessionHasNoErrors();
    $ride = RideRequest::firstOrFail();
    expect($ride->stop_index)->toBe(1);
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.request', $ride))->assertOk()->assertJsonPath('data.ride.stop_index', 1)->assertJsonPath('data.offer.stops.1', 'Arcella');

    cpAction($this, carpoolPerson(), 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1, 'stop_index' => 3])->assertUnprocessable();
    cpAction($this, $this->driver, 'update', ['offer_id' => $offer->id, 'revision' => 1, 'zone' => 'Prato della Valle', 'departure_at' => '2026-10-10T18:00:00Z',
        'capacity' => 3, 'accessibility' => 'not_specified', 'stops' => ['Stazione', 'Arcella', 'Ospedale', 'Fiera']])->assertUnprocessable();
    expect(RideRequest::count())->toBe(1)->and($offer->fresh()->stops)->toBe(['Stazione', 'Arcella', 'Ospedale']);
});

it('un estraneo non modifica, chiude, annulla né decide sull\'offerta altrui, che resta com\'era', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer);
    $stranger = carpoolPerson();

    cpAction($this, $stranger, 'update', ['offer_id' => $offer->id, 'revision' => 1, 'zone' => 'Altrove', 'departure_at' => '2026-10-10T18:00:00Z',
        'capacity' => 1, 'accessibility' => 'not_specified'])->assertForbidden();
    foreach (['close', 'reopen', 'cancel'] as $action) {
        cpAction($this, $stranger, $action, ['offer_id' => $offer->id])->assertForbidden();
    }
    cpAction($this, $stranger, 'publish', ['offer_id' => $offer->id, 'driver_declaration' => true])->assertForbidden();
    cpAction($this, $stranger, 'accept', ['request_id' => $ride->id])->assertForbidden();
    cpAction($this, $stranger, 'decline', ['request_id' => $ride->id])->assertForbidden();
    percorsoCpPost($this, $stranger, 'cancel', ['offer_id' => $offer->id])->assertForbidden();

    $offer->refresh();
    expect($offer->status)->toBe(RideStatus::Open)->and($offer->zone)->toBe('Prato della Valle')->and($offer->capacity)->toBe(3)
        ->and($ride->fresh()->status)->toBe(RideRequestStatus::Pending)
        ->and(CarpoolAudit::where('actor_id', $stranger->id)->count())->toBe(0)
        ->and(DB::table('carpool_commands')->where('user_id', $stranger->id)->count())->toBe(0);
});

it('una bozza sulla tratta già offerta si pubblica solo dopo aver annullato l\'altra offerta', function (): void {
    $open = cpOffer($this);
    $draft = cpOffer($this, data: ['zone' => 'Arcella', 'draft' => true]);
    expect($draft->status)->toBe(RideStatus::Draft);

    cpAction($this, $this->driver, 'publish', ['offer_id' => $draft->id, 'driver_declaration' => true])->assertConflict()
        ->assertJsonPath('error.message', __('carpool.errors.occupied'));
    flussoCpWeb($this, $this->driver);
    $this->from(route('carpool.offer', $draft))->post(route('carpool.action', 'publish'), ['request_key' => (string) Str::uuid(), 'offer_id' => $draft->id, 'driver_declaration' => '1'])
        ->assertRedirect(route('carpool.offer', $draft))->assertSessionHasErrors(['form' => __('carpool.errors.occupied')]);
    expect($draft->fresh()->status)->toBe(RideStatus::Draft);

    cpAction($this, $this->driver, 'cancel', ['offer_id' => $open->id])->assertOk();
    cpAction($this, $this->driver, 'publish', ['offer_id' => $draft->id, 'driver_declaration' => true])->assertOk();
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(1, 'data.offers')->assertJsonPath('data.offers.0.zone', 'Arcella');
});
