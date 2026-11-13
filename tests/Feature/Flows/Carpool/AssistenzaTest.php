<?php

declare(strict_types=1);

use App\Enums\CarpoolCaseStatus;
use App\Models\CarpoolAudit;
use App\Models\CarpoolCase;
use App\Models\RideConversation;
use App\Services\Carpool\CommunitySafety;
use App\Services\Carpool\RideChat;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * Segnalazione di un problema, caso di assistenza con le risposte dello staff e del
 * passeggero, e consultazione delle prove riservata all'amministrazione.
 */
require_once __DIR__.'/flussi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('il passeggero segnala un problema, l\'assistenza risponde e lui replica dal caso', function (): void {
    $ride = cpAccepted($this);
    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.cases'))->assertOk()->assertSee(__('carpool.empty_cases'));

    $response = $this->post(route('carpool.report'), ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id, 'reason' => 'money',
        'body' => 'Il conducente mi ha chiesto dieci euro per la benzina.']);
    $case = CarpoolCase::firstOrFail();
    $response->assertRedirect(route('carpool.case', $case));
    expect($case->reporter_id)->toBe($this->passenger->id)->and($case->ride_request_id)->toBe($ride->id)->and($case->ride_offer_id)->toBe($ride->ride_offer_id)
        ->and($case->status)->toBe(CarpoolCaseStatus::Open)->and($case->reason)->toBe('money')
        ->and(CarpoolAudit::where('action', 'case_opened')->where('subject_id', $case->id)->count())->toBe(1);

    $this->get(route('carpool.cases'))->assertOk();
    $this->getJson(route('carpool.cases'))->assertJsonCount(1, 'data.cases')->assertJsonPath('data.cases.0.id', $case->id);
    $this->getJson(route('carpool.case', $case))->assertJsonPath('data.body', 'Il conducente mi ha chiesto dieci euro per la benzina.')->assertJsonPath('data.messages', []);

    $admin = cpStaff();
    app(CommunitySafety::class)->manage($admin, $case, 'reply', 'Grazie, ci dici quando è successo?', ['revision' => $case->revision, 'recipient_id' => $this->passenger->id]);
    expect($case->fresh()->status)->toBe(CarpoolCaseStatus::Waiting)
        ->and($this->passenger->notifications()->where('type', 'carpool.support')->count())->toBe(1);

    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.case', $case))->assertOk()->assertSee('Grazie, ci dici quando è successo?');
    $this->post(route('carpool.case.reply', $case), ['request_key' => (string) Str::uuid(), 'body' => 'Ieri sera, prima della partenza.'])
        ->assertRedirect(route('carpool.case', $case));
    $case->refresh();
    expect($case->status)->toBe(CarpoolCaseStatus::Open)->and($case->messages()->where('author_id', $this->passenger->id)->value('body'))->toBe('Ieri sera, prima della partenza.');
    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/cases/'.$case->id)->assertOk()->assertJsonCount(2, 'data.messages')->assertJsonPath('data.messages.1.mine', true);

    // Il conducente, non coinvolto nella corrispondenza, non vede né risponde al caso.
    flussoCpWeb($this, $this->driver);
    $this->get(route('carpool.case', $case))->assertForbidden();
    $this->post(route('carpool.case.reply', $case), ['request_key' => (string) Str::uuid(), 'body' => 'Non è vero niente.'])->assertForbidden();
    $this->getJson(route('carpool.cases'))->assertJsonCount(0, 'data.cases');
    expect($case->messages()->count())->toBe(2);
});

it('dall\'app la segnalazione è rifiutata a chi non partecipa al passaggio, con motivo inventato o testo troppo breve', function (): void {
    $ride = cpAccepted($this);
    $stranger = carpoolPerson();

    Sanctum::actingAs($stranger);
    $this->postJson('/api/v1/carpool/reports', ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id, 'reason' => 'safety', 'body' => 'Voglio segnalare questa corsa'])->assertNotFound();
    Sanctum::actingAs($this->passenger->fresh());
    $this->postJson('/api/v1/carpool/reports', ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id, 'reason' => 'boh', 'body' => 'Motivo inesistente'])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['reason']]]);
    $this->postJson('/api/v1/carpool/reports', ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id, 'reason' => 'safety', 'body' => 'no'])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['body']]]);
    // Un messaggio che non appartiene alla chat del passaggio non si allega.
    $this->postJson('/api/v1/carpool/reports', ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id, 'message_id' => 999999, 'reason' => 'harassment', 'body' => 'Messaggio offensivo in chat'])
        ->assertNotFound();
    expect(CarpoolCase::count())->toBe(0);

    // Il conducente segnala con il messaggio vero della chat: il caso nasce e compare nella sua lista.
    $chat = RideConversation::firstOrFail();
    $message = app(RideChat::class)->send($this->passenger, $chat, 'Messaggio sgradevole', (string) Str::uuid());
    Sanctum::actingAs($this->driver->fresh());
    $case = $this->postJson('/api/v1/carpool/reports', ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id, 'message_id' => $message, 'reason' => 'harassment', 'body' => 'Messaggio offensivo in chat'])
        ->assertOk()->assertJsonPath('data.entity', 'case')->json('data.id');
    expect(CarpoolCase::findOrFail($case)->message_id)->toBe($message);
    $this->getJson('/api/v1/carpool/cases')->assertOk()->assertJsonCount(1, 'data.cases')->assertJsonPath('data.cases.0.reason', 'harassment');
    $this->postJson('/api/v1/carpool/cases/'.$case, ['request_key' => (string) Str::uuid(), 'body' => 'Aggiungo un dettaglio'])->assertOk();
    Sanctum::actingAs($stranger->fresh());
    $this->getJson('/api/v1/carpool/cases/'.$case)->assertForbidden();
    $this->postJson('/api/v1/carpool/cases/'.$case, ['request_key' => (string) Str::uuid(), 'body' => 'Mi intrometto'])->assertForbidden();
});

it('l\'amministratore consulta e scarica le prove del caso con password e motivo; moderatori e utenti no', function (): void {
    $ride = cpAccepted($this);
    $chat = RideConversation::firstOrFail();
    app(RideChat::class)->send($this->driver, $chat, 'Sono dieci euro a testa', (string) Str::uuid());
    $case = cpCase($this, $ride);
    $admin = cpStaff();

    flussoCpWeb($this, $admin);
    $this->from(route('carpool.cases'))->post(route('carpool.evidence', $case), ['reason' => 'Verifica della segnalazione', 'password' => 'sbagliata'])
        ->assertSessionHasErrors('password');
    $this->from(route('carpool.cases'))->post(route('carpool.evidence', $case), ['reason' => 'x', 'password' => 'password'])->assertSessionHasErrors('reason');
    expect(CarpoolAudit::where('action', 'evidence_view')->count())->toBe(0);

    $this->post(route('carpool.evidence', $case), ['reason' => 'Verifica della segnalazione', 'password' => 'password'])->assertOk()
        ->assertSee('Sono dieci euro a testa')->assertSee('request_accepted')->assertHeader('Cache-Control', 'no-store, private');
    expect(CarpoolAudit::where('action', 'evidence_view')->where('actor_id', $admin->id)->where('subject_id', $case->id)->count())->toBe(1);

    $download = $this->post(route('carpool.evidence', $case), ['reason' => 'Esportazione per la denuncia', 'password' => 'password', 'export' => '1'])->assertOk()
        ->assertHeader('Content-Type', 'application/json');
    $evidence = json_decode($download->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
    expect($evidence['case_id'])->toBe($case->id)->and($evidence['operator_id'])->toBe($admin->id)->and($evidence['sha256'])->toHaveLength(64)
        ->and(array_column($evidence['messages'], 'body'))->toBe(['Sono dieci euro a testa'])
        ->and(CarpoolAudit::where('action', 'evidence_export')->count())->toBe(1);

    flussoCpWeb($this, cpStaff('moderator'));
    $this->post(route('carpool.evidence', $case), ['reason' => 'Verifica della segnalazione', 'password' => 'password'])->assertForbidden();
    flussoCpWeb($this, $this->passenger);
    $this->post(route('carpool.evidence', $case), ['reason' => 'Verifica della segnalazione', 'password' => 'password'])->assertForbidden();
    flussoCpGuest();
    $this->post(route('carpool.evidence', $case), ['reason' => 'Verifica della segnalazione', 'password' => 'password'])->assertRedirect(route('login'));
    expect(CarpoolAudit::whereIn('action', ['evidence_view', 'evidence_export'])->count())->toBe(2)
        ->and(DB::table('carpool_case_messages')->count())->toBe(0);
});
