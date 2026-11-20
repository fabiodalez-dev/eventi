<?php

declare(strict_types=1);

use App\Enums\CarpoolCaseStatus;
use App\Models\CarpoolCase;
use App\Services\Carpool\CommunitySafety;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * L'assistenza dei passaggi seguita da chi segnala e da chi è coinvolto: segnalazione dalla
 * pagina dell'offerta, presa in carico, risposta e chiusura dello staff, la persona
 * coinvolta che risponde, casi riaperti, segnalazioni generiche o incoerenti, utenti
 * sospesi, doppio invio. Protegge che ognuno veda solo la propria corrispondenza.
 */
require_once __DIR__.'/percorsi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('il conducente segnala dalla pagina dell\'offerta e segue il caso mentre lo staff lo prende in carico, risponde e lo chiude', function (): void {
    $ride = cpAccepted($this);
    $offer = $ride->offer;
    flussoCpWeb($this, $this->driver);
    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee('data-carpool-report', false)->assertSee('name="offer_id" value="'.$offer->id.'"', false);
    $this->post(route('carpool.report'), ['request_key' => (string) Str::uuid(), 'offer_id' => $offer->id, 'reason' => 'safety', 'body' => 'Un passeggero insiste per guidare lui'])
        ->assertRedirect();
    $case = CarpoolCase::firstOrFail();
    $status = fn (): string => $this->getJson(route('carpool.case', $case))->assertOk()->json('data.case_status');
    expect($case->ride_offer_id)->toBe($offer->id)->and($case->ride_request_id)->toBeNull()->and($status())->toBe(__('carpool.CarpoolCaseStatus.open'));

    $admin = cpStaff();
    $safety = app(CommunitySafety::class);
    $safety->manage($admin, $case, 'assign', 'Prendo in carico la segnalazione', ['revision' => $case->revision]);
    flussoCpWeb($this, $this->driver);
    expect($status())->toBe(__('carpool.CarpoolCaseStatus.assigned'));

    $safety->manage($admin, $case->fresh(), 'reply', 'Abbiamo scritto al passeggero, grazie.', ['revision' => $case->fresh()->revision, 'recipient_id' => $this->driver->id]);
    flussoCpWeb($this, $this->driver);
    expect($status())->toBe(__('carpool.CarpoolCaseStatus.waiting'))->and($this->driver->notifications()->where('type', 'carpool.support')->count())->toBe(1);
    $this->get(route('carpool.case', $case))->assertOk()->assertSee('Abbiamo scritto al passeggero, grazie.')->assertSee('Un passeggero insiste per guidare lui')
        ->assertDontSee('Prendo in carico la segnalazione');

    $safety->manage($admin, $case->fresh(), 'resolve', 'Chiarito con entrambi', ['revision' => $case->fresh()->revision]);
    flussoCpWeb($this, $this->driver);
    expect($status())->toBe(__('carpool.CarpoolCaseStatus.resolved'));
    $this->get(route('carpool.cases'))->assertOk()->assertSee(__('carpool.CarpoolCaseStatus.resolved'))->assertSee(__('carpool.reasons.safety'));
});

it('lo staff scrive al conducente nel caso del passeggero: lui vede solo il messaggio a lui, non la segnalazione, e risponde', function (): void {
    $ride = cpAccepted($this);
    $case = cpCase($this, $ride);
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.cases'))->assertJsonCount(0, 'data.cases');
    $this->get(route('carpool.case', $case))->assertForbidden();

    app(CommunitySafety::class)->manage(cpStaff(), $case, 'reply', 'Puoi raccontarci la tua versione?', ['revision' => $case->revision, 'recipient_id' => $this->driver->id]);
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.cases'))->assertJsonCount(1, 'data.cases')->assertJsonPath('data.cases.0.id', $case->id);
    $this->getJson(route('carpool.case', $case))->assertJsonPath('data.body', null)->assertJsonCount(1, 'data.messages')
        ->assertJsonPath('data.messages.0.body', 'Puoi raccontarci la tua versione?')->assertJsonPath('data.messages.0.mine', false);
    $this->get(route('carpool.case', $case))->assertOk()->assertDontSee('Vorrei chiarire questo problema.');
    $this->post(route('carpool.case.reply', $case), ['request_key' => (string) Str::uuid(), 'body' => 'Sono arrivato in orario, lo giuro'])->assertRedirect(route('carpool.case', $case));
    expect($case->fresh()->status)->toBe(CarpoolCaseStatus::Open);

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.case', $case))->assertJsonPath('data.body', 'Vorrei chiarire questo problema.')->assertJsonCount(0, 'data.messages');
    $this->get(route('carpool.case', $case))->assertOk()->assertDontSee('Sono arrivato in orario')->assertDontSee('Puoi raccontarci la tua versione?');
});

it('il passeggero risponde a un caso già risolto e lo riapre, e lo staff lo ritrova aperto', function (): void {
    $ride = cpAccepted($this);
    $case = cpCase($this, $ride);
    $admin = cpStaff();
    app(CommunitySafety::class)->manage($admin, $case, 'resolve', 'Nessun seguito necessario', ['revision' => $case->revision]);
    expect($case->fresh()->status)->toBe(CarpoolCaseStatus::Resolved)->and($case->fresh()->closed_at)->not->toBeNull();

    Sanctum::actingAs($this->passenger->fresh());
    $this->postJson('/api/v1/carpool/cases/'.$case->id, ['request_key' => (string) Str::uuid(), 'body' => 'È successo di nuovo stasera'])->assertOk()->assertJsonPath('data.entity', 'case');
    $case->refresh();
    expect($case->status)->toBe(CarpoolCaseStatus::Open)->and($case->closed_at)->toBeNull();
    $this->getJson('/api/v1/carpool/cases')->assertJsonPath('data.cases.0.status', 'open');
    // La revisione è cambiata: lo staff deve ripartire dal caso aggiornato.
    expect(fn () => app(CommunitySafety::class)->manage($admin, $case, 'resolve', 'Chiudo di nuovo', ['revision' => $case->revision - 1]))->toThrow(HttpException::class);
    app(CommunitySafety::class)->manage($admin, $case, 'assign', 'Riprendo il caso', ['revision' => $case->revision]);
    expect($case->fresh()->status)->toBe(CarpoolCaseStatus::Assigned);
});

it('una segnalazione generica senza passaggio si apre comunque e compare fra le segnalazioni con il suo motivo', function (): void {
    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.cases'))->assertOk()->assertSee('data-carpool-report', false);
    $this->post(route('carpool.report'), ['request_key' => (string) Str::uuid(), 'reason' => 'money', 'body' => 'Un utente chiede soldi per i passaggi in bacheca'])->assertRedirect();
    $case = CarpoolCase::firstOrFail();
    expect($case->ride_offer_id)->toBeNull()->and($case->ride_request_id)->toBeNull()->and($case->reason)->toBe('money');
    $this->get(route('carpool.cases'))->assertOk()->assertSee(__('carpool.reasons.money'))->assertSee(__('carpool.case', ['id' => $case->id]));
    $this->get(route('carpool.case', $case))->assertOk()->assertSee('Un utente chiede soldi per i passaggi in bacheca');
});

it('chi indica offerta e richiesta che non stanno insieme, o un\'offerta che non può vedere, è respinto senza aprire casi', function (): void {
    $ride = cpAccepted($this);
    $unrelated = cpOffer($this, carpoolPerson(), ['zone' => 'Arcella', 'leg' => 'return', 'departure_at' => '2026-10-10T23:00:00Z']);
    $draft = cpOffer($this, carpoolPerson(), ['zone' => 'Stazione', 'draft' => true]);

    Sanctum::actingAs($this->passenger->fresh());
    $this->postJson('/api/v1/carpool/reports', ['request_key' => (string) Str::uuid(), 'offer_id' => $unrelated->id, 'request_id' => $ride->id, 'reason' => 'other', 'body' => 'Dati incoerenti'])
        ->assertUnprocessable();
    $this->postJson('/api/v1/carpool/reports', ['request_key' => (string) Str::uuid(), 'offer_id' => $draft->id, 'reason' => 'other', 'body' => 'Bozza altrui'])->assertNotFound();
    $this->postJson('/api/v1/carpool/reports', ['request_key' => (string) Str::uuid(), 'offer_id' => 999999, 'reason' => 'other', 'body' => 'Offerta inesistente'])->assertNotFound();
    expect(CarpoolCase::count())->toBe(0);

    $this->postJson('/api/v1/carpool/reports', ['request_key' => (string) Str::uuid(), 'offer_id' => $ride->ride_offer_id, 'request_id' => $ride->id, 'reason' => 'other', 'body' => 'Dati coerenti'])
        ->assertOk();
    expect(CarpoolCase::count())->toBe(1);
});

it('un passeggero sospeso legge e risponde alle sue segnalazioni e ne apre una nuova sul passaggio annullato', function (): void {
    $ride = cpAccepted($this);
    $case = cpCase($this, $ride);
    app(CommunitySafety::class)->manage(cpStaff(), $case, 'reply', 'Ti abbiamo sospeso in attesa di verifica', ['revision' => $case->revision, 'recipient_id' => $this->passenger->id]);
    $this->passenger->forceFill(['carpool_suspended_at' => now()])->save();
    expect($ride->fresh()->status->value)->toBe('cancelled');

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.requirements'))->assertJsonPath('data.access.reason', 'suspended');
    $this->get(route('carpool.cases'))->assertOk()->assertSee(__('carpool.case', ['id' => $case->id]));
    $this->get(route('carpool.case', $case))->assertOk()->assertSee('Ti abbiamo sospeso in attesa di verifica');
    $this->post(route('carpool.case.reply', $case), ['request_key' => (string) Str::uuid(), 'body' => 'Posso spiegare tutto'])->assertRedirect(route('carpool.case', $case));
    $this->post(route('carpool.report'), ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id, 'reason' => 'other', 'body' => 'Il conducente mi ha insultato in chat'])->assertRedirect();
    expect(CarpoolCase::where('reporter_id', $this->passenger->id)->count())->toBe(2)
        ->and($case->messages()->where('author_id', $this->passenger->id)->value('body'))->toBe('Posso spiegare tutto');
});

it('lo stesso modulo di segnalazione inviato due volte apre un solo caso e una sola risposta', function (): void {
    $ride = cpAccepted($this);
    flussoCpWeb($this, $this->passenger);
    $form = ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id, 'reason' => 'no_show', 'body' => 'Il conducente non si è presentato'];
    $this->post(route('carpool.report'), $form)->assertRedirect();
    $case = CarpoolCase::firstOrFail();
    $this->post(route('carpool.report'), $form)->assertRedirect(route('carpool.case', $case));
    expect(CarpoolCase::count())->toBe(1);

    $reply = ['request_key' => (string) Str::uuid(), 'body' => 'Aggiungo che ho aspettato mezz\'ora'];
    $this->post(route('carpool.case.reply', $case), $reply)->assertRedirect(route('carpool.case', $case));
    $this->post(route('carpool.case.reply', $case), $reply)->assertRedirect(route('carpool.case', $case));
    expect($case->messages()->count())->toBe(1);
    $this->getJson(route('carpool.case', $case))->assertJsonCount(1, 'data.messages');
});
