<?php

declare(strict_types=1);

use App\Enums\RideRequestStatus;
use App\Enums\RideStatus;
use App\Models\CarpoolAudit;
use App\Models\CarpoolProfile;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideTemplate;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Flussi di chi cerca un passaggio per una data e di chi lo offre: pagine pubbliche,
 * regole e requisiti, dichiarazione, offerta dal modulo web, bozze, chiusura e riapertura,
 * modifica delle condizioni, filtri della ricerca, modelli riutilizzabili.
 *
 * `tests/Pest.php` applica cpSetup solo a Feature/Carpool: qui lo si chiama a mano.
 */
require_once __DIR__.'/flussi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('un ospite consulta data, regole e requisiti ma per agire viene mandato ad accedere', function (): void {
    cpOffer($this);
    flussoCpGuest();

    $this->get(route('carpool.dates', $this->date))->assertOk()->assertSee($this->date->event->title)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDontSee('Prato della Valle');
    $this->getJson(route('carpool.dates', $this->date))->assertOk()
        ->assertJsonPath('data.access.eligible', false)->assertJsonPath('data.access.reason', 'login')
        ->assertJsonPath('data.offers', [])->assertJsonPath('data.my_offers', []);
    $this->get(route('carpool.terms'))->assertOk();
    $this->getJson(route('carpool.terms'))->assertOk()->assertJsonPath('data.version', config('carpool.terms_version'));
    $this->get(route('carpool.requirements'))->assertOk();
    $this->get(route('carpool.create', $this->date))->assertOk()->assertSee($this->date->event->title);

    foreach (['carpool.index', 'carpool.summary', 'carpool.chats', 'carpool.cases'] as $name) {
        $this->get(route($name))->assertRedirect(route('login'));
    }
    $this->get(route('carpool.reviews', $this->driver))->assertRedirect(route('login'));
    $this->post(route('carpool.action', 'offer'), flussoCpOfferForm($this))->assertRedirect(route('login'));
    $this->post(route('carpool.discovery', 'search'), ['request_key' => (string) Str::uuid()])->assertRedirect(route('login'));
    $this->post(route('carpool.report'), ['request_key' => (string) Str::uuid(), 'reason' => 'other', 'body' => 'Segnalazione di prova'])->assertRedirect(route('login'));

    expect(RideOffer::count())->toBe(1)->and(DB::table('carpool_cases')->count())->toBe(0);
});

it('dall\'app un utente abilitato legge regole, requisiti, data e modulo di offerta, mentre l\'ospite riceve 401', function (): void {
    flussoCpGuest();
    foreach (['/api/v1/carpool/terms', '/api/v1/carpool/me', '/api/v1/carpool/occurrences/'.$this->date->id] as $path) {
        $this->getJson($path)->assertUnauthorized();
    }
    $this->postJson('/api/v1/carpool/actions/offer', [])->assertUnauthorized();

    $offer = cpOffer($this);
    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/terms')->assertOk()->assertJsonPath('data.version', config('carpool.terms_version'))
        ->assertJsonPath('data.access.eligible', true);
    $this->getJson('/api/v1/carpool/requirements')->assertOk()->assertJsonPath('data.access.reason', null);
    $this->getJson('/api/v1/carpool/occurrences/'.$this->date->id)->assertOk()
        ->assertJsonPath('data.occurrence_id', $this->date->id)->assertJsonPath('data.timezone', 'Europe/Rome')
        ->assertJsonCount(1, 'data.offers')->assertJsonPath('data.offers.0.id', $offer->id)
        ->assertJsonPath('data.offers.0.can_request', true)->assertJsonPath('data.offers.0.available', 3);
    $this->getJson('/api/v1/carpool/occurrences/'.$this->date->id.'/offer')->assertOk()
        ->assertJsonPath('data.occurrence_id', $this->date->id)->assertJsonPath('data.templates', []);
    $this->getJson('/api/v1/carpool/offers/'.$offer->id)->assertOk()->assertJsonPath('data.offer.zone', 'Prato della Valle')
        ->assertJsonPath('data.offer.is_own', false)->assertJsonPath('data.requests', []);
    // Una data che non esiste non apre nulla.
    $this->getJson('/api/v1/carpool/occurrences/999999')->assertNotFound();
});

it('una persona nuova legge le regole, dichiara maggiore età e torna al modulo di offerta', function (): void {
    $user = carpoolPerson(false);
    flussoCpWeb($this, $user);

    $this->getJson(route('carpool.requirements'))->assertOk()->assertJsonPath('data.access.eligible', false)->assertJsonPath('data.access.reason', 'adult');
    $this->get(route('carpool.terms'))->assertOk();
    $this->getJson(route('carpool.dates', $this->date))->assertOk()->assertJsonPath('data.access.reason', 'adult')->assertJsonPath('data.offers', []);

    $returnTo = '/passaggi/date/'.$this->date->id.'/offri';
    $this->post(route('carpool.action', 'declare'), ['request_key' => (string) Str::uuid(), 'adult' => '1', 'terms' => '1',
        'version' => config('carpool.terms_version'), 'return_to' => $returnTo])->assertRedirect($returnTo)->assertSessionHas('status');

    $profile = CarpoolProfile::where('user_id', $user->id)->firstOrFail();
    expect($profile->adult_declared_at)->not->toBeNull()->and($profile->terms_version)->toBe(config('carpool.terms_version'))
        ->and($profile->terms_accepted_at)->not->toBeNull()
        ->and(CarpoolAudit::where('action', 'adult_and_terms_accepted')->where('actor_id', $user->id)->count())->toBe(1);

    // La richiesta successiva ricarica l'utente, come farebbe il browser con una nuova pagina.
    flussoCpWeb($this, $user);

    $this->getJson(route('carpool.dates', $this->date))->assertOk()->assertJsonPath('data.access.eligible', true);
    $this->get($returnTo)->assertOk();
});

it('la dichiarazione rifiuta un indirizzo di ritorno esterno e una versione delle regole superata', function (): void {
    $user = carpoolPerson(false);
    flussoCpWeb($this, $user);

    $this->from(route('carpool.requirements'))->post(route('carpool.action', 'declare'), ['request_key' => (string) Str::uuid(), 'adult' => '1', 'terms' => '1',
        'version' => config('carpool.terms_version'), 'return_to' => 'https://esempio.invalid/passaggi'])->assertRedirect(route('carpool.requirements'))->assertSessionHasErrors('return_to');
    $this->post(route('carpool.action', 'declare'), ['request_key' => (string) Str::uuid(), 'adult' => '1', 'terms' => '1', 'version' => '2020-01-01'])->assertStatus(409);

    expect(CarpoolProfile::where('user_id', $user->id)->whereNotNull('adult_declared_at')->exists())->toBeFalse();
    $this->getJson(route('carpool.requirements'))->assertJsonPath('data.access.reason', 'adult');
});

it('il conducente apre il modulo, pubblica il passaggio dal sito e lo ritrova fra le offerte della data', function (): void {
    flussoCpWeb($this, $this->driver);
    $this->get(route('carpool.create', $this->date))->assertOk()->assertSee($this->date->event->title);

    $response = $this->post(route('carpool.action', 'offer'), flussoCpOfferForm($this, ['note' => 'Parto puntuale', 'stops' => ['Stazione', '']]));
    $offer = RideOffer::firstOrFail();
    $response->assertRedirect(route('carpool.offer', $offer))->assertSessionHas('status');

    // L'ora locale 20:00 di Padova in ottobre è 18:00 UTC.
    expect($offer->status)->toBe(RideStatus::Open)->and($offer->driver_id)->toBe($this->driver->id)
        ->and($offer->departure_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-10 18:00')
        ->and($offer->stops)->toBe(['Stazione'])->and($offer->revision)->toBe(1)
        ->and(DB::table('ride_occupancies')->where('user_id', $this->driver->id)->where('ride_offer_id', $offer->id)->count())->toBe(1)
        ->and(CarpoolProfile::where('user_id', $this->driver->id)->value('driver_declared_at'))->not->toBeNull()
        ->and(CarpoolAudit::where('action', 'ride_offered')->where('subject_id', $offer->id)->count())->toBe(1);

    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee('Prato della Valle')->assertSee('Parto puntuale');
    $this->getJson(route('carpool.dates', $this->date))->assertJsonPath('data.my_offers.0.id', $offer->id)->assertJsonPath('data.offers.0.is_own', true);

    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.dates', $this->date))->assertOk()->assertSee('Prato della Valle');
    $this->getJson(route('carpool.dates', $this->date))->assertJsonPath('data.offers.0.id', $offer->id)->assertJsonPath('data.offers.0.can_request', true);

    // Una seconda offerta sulla stessa tratta della stessa data non passa.
    flussoCpWeb($this, $this->driver);
    $this->post(route('carpool.action', 'offer'), flussoCpOfferForm($this, ['zone' => 'Arcella']))->assertStatus(409);
    // Né una partenza fuori dalla finestra della data.
    $this->post(route('carpool.action', 'offer'), flussoCpOfferForm($this, ['leg' => 'return', 'departure_at' => '2026-10-20T20:00']))->assertStatus(422);
    expect(RideOffer::count())->toBe(1);
});

it('una bozza resta invisibile finché non è pubblicata; chiusa non riceve richieste, riaperta sì', function (): void {
    $offer = cpOffer($this, data: ['draft' => true]);
    expect($offer->status)->toBe(RideStatus::Draft)->and(DB::table('ride_occupancies')->count())->toBe(0);

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(0, 'data.offers');
    $this->get(route('carpool.offer', $offer))->assertForbidden();

    flussoCpWeb($this, $this->driver);
    $this->post(route('carpool.action', 'publish'), ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id, 'driver_declaration' => '1'])
        ->assertRedirect(route('carpool.offer', $offer));
    expect($offer->fresh()->status)->toBe(RideStatus::Open)->and(DB::table('ride_occupancies')->where('ride_offer_id', $offer->id)->count())->toBe(1);

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(1, 'data.offers');

    flussoCpWeb($this, $this->driver);
    $this->post(route('carpool.action', 'close'), ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id])->assertRedirect(route('carpool.offer', $offer));
    expect($offer->fresh()->status)->toBe(RideStatus::Closed);

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(0, 'data.offers');
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertNotFound();

    // Solo il conducente può riaprire.
    cpAction($this, $this->passenger, 'reopen', ['offer_id' => $offer->id])->assertForbidden();
    cpAction($this, $this->driver, 'reopen', ['offer_id' => $offer->id])->assertOk()->assertJsonPath('data.entity', 'offer');
    expect($offer->fresh()->status)->toBe(RideStatus::Open);
    $ride = cpRequest($this, $offer->fresh());
    expect($ride->status)->toBe(RideRequestStatus::Pending);
});

it('cambiare le condizioni annulla le richieste in attesa, avvisa il passeggero e richiede la nuova revisione', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer);

    cpAction($this, $this->driver, 'update', ['offer_id' => $offer->id, 'revision' => 1, 'zone' => 'Stazione FS', 'departure_at' => '2026-10-10T17:30:00Z',
        'capacity' => 4, 'accessibility' => 'folding_chair', 'accessibility_note' => 'Bagagliaio ampio'])->assertOk();

    $offer->refresh();
    expect($offer->revision)->toBe(2)->and($offer->zone)->toBe('Stazione FS')->and($offer->capacity)->toBe(4)
        ->and($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled)->and($ride->fresh()->close_reason)->toBe('offer_changed')
        ->and($this->passenger->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1)
        ->and($this->driver->notifications()->where('type', 'carpool.cancelled')->count())->toBe(0);

    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 1, 'seats' => 1])->assertConflict();
    cpAction($this, $this->passenger, 'request', ['offer_id' => $offer->id, 'revision' => 2, 'seats' => 1])->assertOk();
    // Solo la capienza cambia senza toccare la revisione e senza annullare la nuova richiesta.
    cpAction($this, $this->driver, 'update', ['offer_id' => $offer->id, 'revision' => 2, 'zone' => 'Stazione FS', 'departure_at' => '2026-10-10T17:30:00Z',
        'capacity' => 5, 'accessibility' => 'folding_chair', 'accessibility_note' => 'Bagagliaio ampio'])->assertOk();
    expect($offer->fresh()->revision)->toBe(2)->and(RideRequest::where('status', 'pending')->count())->toBe(1);
    // Un altro utente non può modificarla.
    cpAction($this, carpoolPerson(), 'update', ['offer_id' => $offer->id, 'revision' => 2, 'zone' => 'Altrove', 'departure_at' => '2026-10-10T17:30:00Z',
        'capacity' => 5, 'accessibility' => 'not_specified'])->assertForbidden();
});

it('chi cerca filtra le offerte della data per tratta, zona, posti e ordine', function (): void {
    $a = cpOffer($this, data: ['zone' => 'Prato della Valle', 'capacity' => 3, 'departure_at' => '2026-10-10T18:00:00Z']);
    $b = cpOffer($this, carpoolPerson(), ['zone' => 'Stazione', 'capacity' => 1, 'departure_at' => '2026-10-10T17:30:00Z']);
    $c = cpOffer($this, carpoolPerson(), ['leg' => 'return', 'zone' => 'Arcella', 'capacity' => 2, 'departure_at' => '2026-10-10T23:00:00Z']);
    flussoCpWeb($this, $this->passenger);
    $ids = fn (string $query) => array_column($this->getJson(route('carpool.dates', $this->date).$query)->assertOk()->json('data.offers'), 'id');

    expect($ids(''))->toBe([$b->id, $a->id, $c->id])
        ->and($ids('?leg=return'))->toBe([$c->id])
        ->and($ids('?zone=stazione'))->toBe([$b->id])
        ->and($ids('?seats=2'))->toBe([$a->id, $c->id])
        ->and($ids('?sort=recent'))->toBe([$c->id, $b->id, $a->id]);
    $this->getJson(route('carpool.dates', $this->date).'?leg=sideways')->assertUnprocessable();
    $this->get(route('carpool.dates', $this->date).'?zone=Arcella')->assertOk()->assertSee('Arcella')->assertDontSee('Prato della Valle');
});

it('il conducente salva un modello dall\'offerta, lo ritrova nel modulo e lo elimina; altri non possono toccarlo', function (): void {
    $offer = cpOffer($this, data: ['stops' => ['Stazione'], 'note' => 'Niente bagagli grandi']);
    flussoCpWeb($this, $this->driver);

    $this->post(route('carpool.discovery', 'template'), ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id, 'name' => 'Da casa'])
        ->assertRedirect(route('carpool.index'));
    $template = RideTemplate::firstOrFail();
    expect($template->user_id)->toBe($this->driver->id)->and($template->settings['zone'])->toBe('Prato della Valle')
        ->and($template->settings['stops'])->toBe(['Stazione'])->and($template->settings)->not->toHaveKey('departure_at');

    $this->getJson(route('carpool.create', $this->date))->assertOk()->assertJsonPath('data.templates.0.name', 'Da casa');
    $this->getJson(route('carpool.index'))->assertOk()->assertJsonPath('data.templates.0.id', $template->id);

    // Un modello si crea solo dalle proprie offerte e si cancella solo se è proprio.
    cpDiscovery($this, $this->passenger, 'template', ['offer_id' => $offer->id, 'name' => 'Rubato'])->assertNotFound();
    cpDiscovery($this, $this->passenger, 'delete-template', ['template_id' => $template->id])->assertNotFound();
    expect(RideTemplate::count())->toBe(1);

    flussoCpWeb($this, $this->driver);
    $this->post(route('carpool.discovery', 'delete-template'), ['request_key' => (string) Str::uuid(), 'template_id' => $template->id])->assertRedirect(route('carpool.index'));
    expect(RideTemplate::count())->toBe(0)->and(CarpoolAudit::where('action', 'ride_delete-template')->count())->toBe(1);
});
