<?php

declare(strict_types=1);

use App\Models\RideConversation;
use App\Models\RideFeedback;
use App\Models\RideSearch;
use App\Services\Carpool\CarpoolLifecycle;
use App\Services\Carpool\RideChat;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Ricerche salvate, annunci «Cerco passaggio», proposte del conducente, avvisi di
 * corrispondenza del giro periodico, riepilogo degli avvisi, pagina «I miei passaggi»
 * e feedback privato dopo il viaggio.
 */
require_once __DIR__.'/flussi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** I campi del modulo «Avvisami quando c'è un passaggio». */
function flussoCpSearchForm($test, array $data = []): array
{
    return ['request_key' => (string) Str::uuid(), 'occurrence_id' => $test->date->id, 'leg' => 'outbound', 'zone' => 'Prato', 'seats' => 1,
        'accessibility' => 'not_specified', 'earliest_at' => '2026-10-10T17:00:00Z', 'latest_at' => '2026-10-10T19:00:00Z', 'is_public' => 1, 'alerts_enabled' => 0, ...$data];
}

it('chi cerca pubblica un annuncio, il conducente lo vede fra chi cerca e gli propone la sua offerta una volta sola', function (): void {
    flussoCpWeb($this, $this->passenger);
    $this->post(route('carpool.discovery', 'search'), flussoCpSearchForm($this))->assertRedirect(route('carpool.index'));
    $search = RideSearch::firstOrFail();
    expect($search->user_id)->toBe($this->passenger->id)->and($search->active)->toBeTrue()->and($search->is_public)->toBeTrue()
        ->and($search->zone)->toBe('Prato');
    $this->getJson(route('carpool.index'))->assertJsonPath('data.searches.0.id', $search->id)->assertJsonPath('data.searches.0.is_public', true);

    // Un annuncio privato di un'altra persona non compare nella lista pubblica.
    $shy = carpoolPerson();
    cpDiscovery($this, $shy, 'search', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'seats' => 1, 'accessibility' => 'not_specified',
        'earliest_at' => '2026-10-10T17:00:00Z', 'latest_at' => '2026-10-10T19:00:00Z', 'is_public' => false, 'alerts_enabled' => false])->assertOk();

    $offer = cpOffer($this);
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(1, 'data.wanted')
        ->assertJsonPath('data.wanted.0.id', $search->id)->assertJsonPath('data.wanted.0.zone', 'Prato');
    $this->get(route('carpool.dates', $this->date))->assertOk()->assertSee(__('carpool.wanted'));

    $this->post(route('carpool.discovery', 'suggest'), ['request_key' => (string) Str::uuid(), 'search_id' => $search->id, 'offer_id' => $offer->id])
        ->assertRedirect(route('carpool.index'));
    cpDiscovery($this, $this->driver, 'suggest', ['search_id' => $search->id, 'offer_id' => $offer->id])->assertOk();
    expect(DB::table('ride_suggestions')->where('ride_search_id', $search->id)->where('ride_offer_id', $offer->id)->count())->toBe(1)
        ->and($this->passenger->notifications()->where('type', 'carpool.suggestion')->count())->toBe(1);

    // Un'offerta che non corrisponde (altra zona) o un annuncio privato non si possono proporre.
    $far = cpOffer($this, carpoolPerson(), ['zone' => 'Stazione']);
    cpDiscovery($this, $far->driver, 'suggest', ['search_id' => $search->id, 'offer_id' => $far->id])->assertNotFound();
    cpDiscovery($this, $this->driver, 'suggest', ['search_id' => RideSearch::where('user_id', $shy->id)->value('id'), 'offer_id' => $offer->id])->assertNotFound();
    // Né si propone l'offerta di un altro conducente.
    cpDiscovery($this, $this->driver, 'suggest', ['search_id' => $search->id, 'offer_id' => $far->id])->assertNotFound();
    expect($shy->notifications()->count())->toBe(0);
});

it('la ricerca con avvisi riceve una corrispondenza dal giro periodico, che si ferma se la ricerca è spenta e riparte se la si riaccende', function (): void {
    $saved = cpDiscovery($this, $this->passenger, 'search', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'seats' => 1, 'accessibility' => 'not_specified',
        'earliest_at' => '2026-10-10T17:00:00Z', 'latest_at' => '2026-10-10T19:00:00Z', 'is_public' => false, 'alerts_enabled' => true])->assertOk();
    $search = RideSearch::findOrFail($saved->json('data.id'));
    $matches = fn () => $this->passenger->notifications()->where('type', 'carpool.match')->count();

    $first = cpOffer($this);
    cpOffer($this, carpoolPerson(), ['leg' => 'return', 'departure_at' => '2026-10-10T23:00:00Z']);
    cpOffer($this, carpoolPerson(), ['departure_at' => '2026-10-10T12:00:00Z']);
    app(CarpoolLifecycle::class)->tick();
    app(CarpoolLifecycle::class)->tick();
    expect($matches())->toBe(1)
        ->and($this->passenger->notifications()->where('type', 'carpool.match')->first()->data['entity_id'])->toBe($first->id)
        ->and($this->passenger->notifications()->where('type', 'carpool.match')->first()->data['url'])->toBe(route('carpool.offer', $first));

    cpDiscovery($this, $this->passenger, 'toggle-search', ['search_id' => $search->id, 'active' => false])->assertOk();
    expect($search->fresh()->active)->toBeFalse();
    $second = cpOffer($this, carpoolPerson(), ['zone' => 'Arcella', 'departure_at' => '2026-10-10T18:30:00Z']);
    app(CarpoolLifecycle::class)->tick();
    expect($matches())->toBe(1);

    // Solo il proprietario può riaccendere la propria ricerca.
    cpDiscovery($this, $this->driver, 'toggle-search', ['search_id' => $search->id, 'active' => true])->assertNotFound();
    flussoCpWeb($this, $this->passenger);
    $this->post(route('carpool.discovery', 'toggle-search'), ['request_key' => (string) Str::uuid(), 'search_id' => $search->id, 'active' => 1])
        ->assertRedirect(route('carpool.index'));
    app(CarpoolLifecycle::class)->tick();
    expect($matches())->toBe(2)
        ->and($this->passenger->notifications()->where('type', 'carpool.match')->latest('created_at')->get()->pluck('data.entity_id')->all())->toContain($second->id);
    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/summary')->assertOk()->assertJsonPath('data.unread', 2);
});

it('il riepilogo avvisi conta richieste da gestire, avvisi e conversazioni non lette e segue le letture', function (): void {
    $offer = cpOffer($this);
    $first = cpRequest($this, $offer);
    $second = cpRequest($this, $offer, carpoolPerson());

    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.summary'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.pending', 2)->assertJsonPath('data.unread', 2)->assertJsonPath('data.conversations', 0)->assertJsonPath('data.total', 2);

    cpAction($this, $this->driver, 'accept', ['request_id' => $first->id])->assertOk();
    cpAction($this, $this->driver, 'decline', ['request_id' => $second->id])->assertOk();
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.summary'))->assertJsonPath('data.pending', 0);

    $chat = RideConversation::firstOrFail();
    $one = app(RideChat::class)->send($this->driver, $chat, 'Ci vediamo davanti alla basilica', (string) Str::uuid());
    $two = app(RideChat::class)->send($this->driver, $chat, 'Ho la macchina rossa', (string) Str::uuid());

    flussoCpWeb($this, $this->passenger);
    // Un avviso di accettazione; i messaggi contano come una conversazione, non come avvisi.
    $this->getJson(route('carpool.summary'))->assertJsonPath('data.unread', 1)->assertJsonPath('data.conversations', 1)->assertJsonPath('data.total', 2);
    $this->post(route('carpool.chat.action', [$chat, 'preferences']), ['read_through_id' => $one])->assertRedirect();
    $this->getJson(route('carpool.summary'))->assertJsonPath('data.conversations', 1);
    $this->postJson(route('carpool.chat.action', [$chat, 'preferences']), ['read_through_id' => $two])->assertOk()->assertJsonPath('summary.conversations', 0);
    $this->getJson(route('carpool.summary'))->assertJsonPath('data.conversations', 0)->assertJsonPath('data.total', 1);

    // Le preferenze push dei passaggi si cambiano dal profilo dei passaggi.
    cpAction($this, $this->passenger, 'preferences', ['push_enabled' => false])->assertOk()->assertJsonPath('data.entity', 'profile');
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.requirements'))->assertJsonPath('data.access.push_enabled', false);
});

it('la pagina dei miei passaggi separa richieste attive, storico, offerte e ricerche', function (): void {
    $accepted = cpAccepted($this);
    $other = cpOffer($this, carpoolPerson(), ['leg' => 'return', 'zone' => 'Arcella', 'departure_at' => '2026-10-10T23:00:00Z']);
    $gone = cpRequest($this, $other);
    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $gone->id])->assertOk();
    cpDiscovery($this, $this->passenger, 'search', ['occurrence_id' => $this->date->id, 'leg' => 'return', 'seats' => 1, 'accessibility' => 'not_specified',
        'earliest_at' => '2026-10-10T22:30:00Z', 'latest_at' => '2026-10-10T23:30:00Z', 'is_public' => false, 'alerts_enabled' => true])->assertOk();

    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.index'))->assertOk()->assertSee(__('carpool.mine'));
    $this->getJson(route('carpool.index').'?tab=requested')->assertJsonCount(1, 'data.requests')->assertJsonPath('data.requests.0.id', $accepted->id)
        ->assertJsonPath('data.requests.0.offer.zone', 'Prato della Valle');
    $this->getJson(route('carpool.index').'?tab=history')->assertJsonCount(1, 'data.requests')->assertJsonPath('data.requests.0.status', 'withdrawn');
    $this->getJson(route('carpool.index').'?tab=searches')->assertJsonCount(1, 'data.searches')->assertJsonPath('data.searches.0.leg_label', __('carpool.RideLeg.return'));
    $this->getJson(route('carpool.index').'?tab=nonesiste')->assertUnprocessable();

    // Dall'app, senza scheda, arrivano tutte le richieste.
    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/me')->assertOk()->assertJsonCount(2, 'data.requests')->assertJsonCount(0, 'data.offers');
    Sanctum::actingAs($this->driver->fresh());
    $this->getJson('/api/v1/carpool/me')->assertOk()->assertJsonCount(1, 'data.offers')->assertJsonPath('data.offers.0.is_own', true)
        ->assertJsonCount(0, 'data.requests');
});

it('dopo il viaggio il passeggero lascia un feedback privato una sola volta; prima della partenza o da estraneo no', function (): void {
    $ride = cpAccepted($this);

    cpDiscovery($this, $this->passenger, 'feedback', ['request_id' => $ride->id, 'kind' => 'travelled'])->assertForbidden();
    freezeLocal($this->city, '2026-10-11 20:00');

    flussoCpWeb($this, $this->passenger);
    $this->post(route('carpool.discovery', 'feedback'), ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id, 'kind' => 'no_show', 'body' => 'Non si è presentato'])
        ->assertRedirect(route('carpool.index'));
    cpDiscovery($this, $this->passenger, 'feedback', ['request_id' => $ride->id, 'kind' => 'travelled'])->assertOk();
    $feedback = RideFeedback::firstOrFail();
    expect(RideFeedback::count())->toBe(1)->and($feedback->kind->value)->toBe('no_show')->and($feedback->user_id)->toBe($this->passenger->id);

    // Anche il conducente può dirlo, una sola volta e per sé.
    cpDiscovery($this, $this->driver, 'feedback', ['request_id' => $ride->id, 'kind' => 'problem'])->assertOk();
    cpDiscovery($this, carpoolPerson(), 'feedback', ['request_id' => $ride->id, 'kind' => 'travelled'])->assertForbidden();
    cpDiscovery($this, $this->passenger, 'feedback', ['request_id' => $ride->id, 'kind' => 'inventato'])->assertUnprocessable();
    expect(RideFeedback::count())->toBe(2);

    // Il feedback non diventa un voto pubblico.
    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/requests/'.$ride->id)->assertOk()->assertJsonPath('data.ride.can_feedback', true)->assertJsonMissingPath('data.ride.feedback');
});
