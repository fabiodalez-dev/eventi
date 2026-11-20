<?php

declare(strict_types=1);

use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideSearch;
use App\Models\UserBlock;
use App\Services\Carpool\CarpoolLifecycle;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Chi cerca un passaggio: scorrere le pagine della data, filtrare per accessibilità e per
 * tappa, ritrovare un'offerta che si libera, non vedere chi ha bloccato, salvare e
 * aggiornare una ricerca con avvisi, vederla scadere. Protegge che quello che compare
 * nell'elenco sia proprio quello che si può chiedere.
 */
require_once __DIR__.'/percorsi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('chi cerca scorre le pagine dei passaggi della data: la seconda continua senza ripetizioni e conserva l\'ordine scelto', function (): void {
    $offers = collect(range(0, 20))->map(fn (int $minute) => cpOffer($this, carpoolPerson(), ['departure_at' => sprintf('2026-10-10T17:%02d:00Z', $minute)]));
    flussoCpWeb($this, $this->passenger);

    $first = $this->getJson(route('carpool.dates', $this->date))->assertOk()->assertJsonCount(20, 'data.offers')
        ->assertJsonPath('data.has_more', true)->assertJsonPath('data.page', 1);
    $second = $this->getJson(route('carpool.dates', $this->date).'?page=2')->assertOk()->assertJsonCount(1, 'data.offers')->assertJsonPath('data.has_more', false);
    $ids = [...array_column($first->json('data.offers'), 'id'), ...array_column($second->json('data.offers'), 'id')];
    expect($ids)->toBe($offers->pluck('id')->all());

    // Con «più recenti» la prima pagina parte dall'ultima offerta e il collegamento alla seconda tiene l'ordine.
    $this->getJson(route('carpool.dates', $this->date).'?sort=recent')->assertJsonPath('data.offers.0.id', $offers->last()->id);
    $page = $this->get(route('carpool.dates', $this->date).'?sort=recent')->assertOk()->getContent();
    expect(html_entity_decode($page))->toContain('sort=recent&page=2');
    $this->getJson(route('carpool.dates', $this->date).'?sort=recent&page=2')->assertJsonPath('data.offers.0.id', $offers->first()->id);
});

it('il filtro accessibilità mostra solo il posto attrezzato e il passeggero lo richiede da lì', function (): void {
    $plain = cpOffer($this);
    $wheelchair = cpOffer($this, carpoolPerson(), ['zone' => 'Arcella', 'accessibility' => 'wheelchair_space', 'accessibility_note' => 'Pedana posteriore']);
    $folding = cpOffer($this, carpoolPerson(), ['zone' => 'Stazione', 'accessibility' => 'folding_chair']);
    flussoCpWeb($this, $this->passenger);
    $ids = fn (string $query): array => array_column($this->getJson(route('carpool.dates', $this->date).$query)->assertOk()->json('data.offers'), 'id');

    expect($ids('?accessibility=wheelchair_space'))->toBe([$wheelchair->id])
        ->and($ids('?accessibility=folding_chair'))->toBe([$folding->id])
        ->and($ids('?accessibility=not_specified'))->toHaveCount(3);
    $this->get(route('carpool.dates', $this->date).'?accessibility=wheelchair_space')->assertOk()->assertSee('Arcella')->assertDontSee(route('carpool.offer', $plain).'"', false);
    $this->get(route('carpool.offer', $wheelchair))->assertOk()->assertSee('Pedana posteriore')->assertSee(__('carpool.RideAccessibility.wheelchair_space'));

    $this->post(route('carpool.action', 'request'), ['request_key' => (string) Str::uuid(), 'offer_id' => $wheelchair->id, 'revision' => 1, 'seats' => 1, 'note' => 'Viaggio con la mia sedia'])
        ->assertSessionHasNoErrors();
    $ride = RideRequest::firstOrFail();
    expect($ride->ride_offer_id)->toBe($wheelchair->id)->and($wheelchair->driver->notifications()->where('type', 'carpool.requested')->count())->toBe(1);
    flussoCpWeb($this, $wheelchair->driver);
    $this->get(route('carpool.offer', $wheelchair))->assertOk()->assertSee('Viaggio con la mia sedia');
});

it('un\'offerta piena sparisce dalla ricerca e ricompare quando il passeggero confermato rinuncia', function (): void {
    $offer = cpOffer($this, data: ['capacity' => 1]);
    $ride = cpRequest($this, $offer);
    cpAction($this, $this->driver, 'accept', ['request_id' => $ride->id])->assertOk();
    $other = carpoolPerson();

    flussoCpWeb($this, $other);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(0, 'data.offers');
    $this->getJson(route('carpool.offer', $offer))->assertOk()->assertJsonPath('data.offer.available', 0)->assertJsonPath('data.offer.can_request', false);
    $this->get(route('carpool.offer', $offer))->assertOk()->assertDontSee('data-offer-request-form', false);

    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $ride->id])->assertOk();
    flussoCpWeb($this, $other);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(1, 'data.offers')->assertJsonPath('data.offers.0.can_request', true);
    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee('data-offer-request-form', false);
    $new = cpRequest($this, $offer->fresh(), $other);
    cpAction($this, $this->driver, 'accept', ['request_id' => $new->id])->assertOk();
    expect($new->fresh()->status->value)->toBe('accepted');
});

it('i passaggi di un conducente bloccato spariscono dalla ricerca e dall\'indirizzo diretto, e tornano dopo lo sblocco', function (): void {
    $offer = cpOffer($this);
    $block = UserBlock::create(['user_id' => $this->passenger->id, 'blocked_user_id' => $this->driver->id]);

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(0, 'data.offers');
    $this->get(route('carpool.offer', $offer))->assertForbidden();
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertNotFound();
    // Il blocco vale nei due sensi: il conducente non vede l'annuncio di chi lo ha bloccato.
    cpDiscovery($this, $this->passenger, 'search', percorsoCpSearch($this, ['is_public' => true]))->assertOk();
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(0, 'data.wanted');

    $block->delete();
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(1, 'data.wanted');
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(1, 'data.offers');
    expect(cpRequest($this, $offer)->status->value)->toBe('pending');
});

it('una tappa si trova cercando con maiuscole o accenti diversi, e la ricerca salvata su quella tappa riceve un solo avviso', function (): void {
    $arcella = cpOffer($this, data: ['stops' => ['ARCELLA']]);
    $hospital = cpOffer($this, carpoolPerson(), ['zone' => 'Stazione', 'stops' => ['Città della Speranza']]);
    flussoCpWeb($this, $this->passenger);
    $ids = fn (string $zone): array => array_column($this->getJson(route('carpool.dates', $this->date).'?zone='.rawurlencode($zone))->assertOk()->json('data.offers'), 'id');

    expect($ids('arcella'))->toBe([$arcella->id])->and($ids('Città'))->toBe([$hospital->id])->and($ids('speranza'))->toBe([$hospital->id]);

    $saved = cpDiscovery($this, $this->passenger, 'search', percorsoCpSearch($this, ['zone' => 'arcella']))->assertOk();
    app(CarpoolLifecycle::class)->tick();
    app(CarpoolLifecycle::class)->tick();
    $matches = $this->passenger->notifications()->where('type', 'carpool.match')->get();
    expect($matches)->toHaveCount(1)->and($matches->first()->data['entity_id'])->toBe($arcella->id)
        ->and($matches->first()->data['search_id'])->toBe($saved->json('data.id'));
    flussoCpWeb($this, $this->passenger);
    $this->get($matches->first()->data['url'])->assertOk()->assertSee('ARCELLA');
});

it('la ricerca per tappa funziona anche con il server in latin1, come in produzione', function (): void {
    // In produzione MariaDB 10.11 ha character_set_server = latin1: senza un set di caratteri
    // dichiarato, la colonna di JSON_TABLE nasceva latin1 e COLLATE utf8mb4 dava errore 1253.
    DB::statement('SET SESSION character_set_server = latin1');
    $hospital = cpOffer($this, carpoolPerson(), ['zone' => 'Stazione', 'stops' => ['Città della Speranza']]);
    flussoCpWeb($this, $this->passenger);

    $this->getJson(route('carpool.dates', $this->date).'?zone='.rawurlencode('città'))->assertOk()
        ->assertJsonPath('data.offers.0.id', $hospital->id)->assertJsonCount(1, 'data.offers');
    $this->get(route('carpool.dates', $this->date).'?zone=speranza')->assertOk()->assertSee('Stazione');
});

it('una ricerca con la finestra sbagliata torna al modulo con il messaggio sotto «Partenza non prima di», poi quella giusta si salva', function (): void {
    flussoCpWeb($this, $this->passenger);
    $form = fn (array $data = []): array => ['request_key' => (string) Str::uuid(), 'occurrence_id' => $this->date->id, 'leg' => 'outbound', 'zone' => 'Prato', 'seats' => 1,
        'accessibility' => 'not_specified', 'earliest_at' => '2026-10-10T19:00', 'latest_at' => '2026-10-10T21:00', 'is_public' => 1, 'alerts_enabled' => 1, ...$data];

    $this->from(route('carpool.dates', $this->date))->post(route('carpool.discovery', 'search'), $form(['earliest_at' => '2026-10-12T19:00', 'latest_at' => '2026-10-12T21:00']))
        ->assertRedirect(route('carpool.dates', $this->date))->assertSessionHasErrors(['earliest_at' => __('carpool.errors.departure')])->assertSessionHasInput('zone', 'Prato');
    $this->from(route('carpool.dates', $this->date))->post(route('carpool.discovery', 'search'), $form(['latest_at' => '2026-10-10T18:00']))
        ->assertRedirect(route('carpool.dates', $this->date))->assertSessionHasErrors('latest_at');
    expect(RideSearch::count())->toBe(0);

    $this->post(route('carpool.discovery', 'search'), $form())->assertRedirect(route('carpool.index'))->assertSessionHasNoErrors();
    $search = RideSearch::firstOrFail();
    // Le ore locali di Padova (19:00 e 21:00) sono le 17:00 e le 19:00 UTC.
    expect($search->earliest_at->utc()->format('H:i'))->toBe('17:00')->and($search->latest_at->utc()->format('H:i'))->toBe('19:00')
        ->and($search->alerts_enabled)->toBeTrue();
    $this->getJson(route('carpool.index').'?tab=searches')->assertJsonCount(1, 'data.searches')->assertJsonPath('data.searches.0.zone', 'Prato');
});

it('chi cerca e offre sulla stessa data non trova il proprio annuncio fra le persone che cercano', function (): void {
    cpDiscovery($this, $this->passenger, 'search', percorsoCpSearch($this, ['is_public' => true, 'zone' => 'Prato']))->assertOk();
    $own = cpOffer($this, $this->passenger, ['leg' => 'return', 'zone' => 'Centro', 'departure_at' => '2026-10-10T23:00:00Z']);
    $neighbour = carpoolPerson();
    $theirs = cpDiscovery($this, $neighbour, 'search', percorsoCpSearch($this, ['is_public' => true, 'zone' => 'Arcella']))->assertOk()->json('data.id');

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(1, 'data.wanted')->assertJsonPath('data.wanted.0.id', $theirs)
        ->assertJsonPath('data.my_offers.0.id', $own->id);
    $this->get(route('carpool.dates', $this->date))->assertOk()->assertSee('name="search_id" value="'.$theirs.'"', false)
        ->assertDontSee('name="search_id" value="'.RideSearch::where('user_id', $this->passenger->id)->value('id').'"', false);
    // Il conducente dell'andata li vede entrambi.
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(2, 'data.wanted');
});

it('la ricerca scaduta viene spenta dal giro periodico, sparisce dagli annunci e non si riaccende', function (): void {
    $id = cpDiscovery($this, $this->passenger, 'search', percorsoCpSearch($this, ['is_public' => true]))->assertOk()->json('data.id');
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(1, 'data.wanted');

    freezeLocal($this->city, '2026-10-10 21:30');
    app(CarpoolLifecycle::class)->tick();
    expect(RideSearch::findOrFail($id)->active)->toBeFalse();
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(0, 'data.wanted');

    cpDiscovery($this, $this->passenger, 'toggle-search', ['search_id' => $id, 'active' => true])->assertUnprocessable()
        ->assertJsonPath('error.message', __('carpool.errors.closed'));
    expect(RideSearch::findOrFail($id)->active)->toBeFalse();
    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/me?tab=searches')->assertJsonPath('data.searches.0.active', false);
});

it('salvare di nuovo la ricerca della stessa tratta la aggiorna e la riaccende invece di duplicarla', function (): void {
    $first = cpDiscovery($this, $this->passenger, 'search', percorsoCpSearch($this))->assertOk()->json('data.id');
    cpDiscovery($this, $this->passenger, 'toggle-search', ['search_id' => $first, 'active' => false])->assertOk();

    $again = cpDiscovery($this, $this->passenger, 'search', percorsoCpSearch($this, ['zone' => 'Stazione', 'seats' => 2, 'is_public' => true]))->assertOk()->json('data.id');
    $return = cpDiscovery($this, $this->passenger, 'search', percorsoCpSearch($this, ['leg' => 'return', 'earliest_at' => '2026-10-10T22:30:00Z', 'latest_at' => '2026-10-10T23:30:00Z']))
        ->assertOk()->json('data.id');

    $search = RideSearch::findOrFail($first);
    expect($again)->toBe($first)->and($return)->not->toBe($first)->and(RideSearch::count())->toBe(2)
        ->and($search->active)->toBeTrue()->and($search->zone)->toBe('Stazione')->and($search->seats)->toBe(2)->and($search->is_public)->toBeTrue();
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.index').'?tab=searches')->assertJsonCount(2, 'data.searches');
    // Una sola offerta da tre posti: corrisponde ai due posti richiesti e arriva un avviso.
    cpOffer($this, carpoolPerson(), ['zone' => 'Stazione']);
    app(CarpoolLifecycle::class)->tick();
    expect($this->passenger->notifications()->where('type', 'carpool.match')->count())->toBe(1)->and(RideOffer::count())->toBe(1);
});
