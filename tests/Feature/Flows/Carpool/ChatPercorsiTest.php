<?php

declare(strict_types=1);

use App\Enums\RideRequestStatus;
use App\Models\CarpoolCase;
use App\Models\UserBlock;
use App\Services\Carpool\CarpoolLifecycle;
use App\Services\Carpool\CommunitySafety;
use App\Services\Carpool\RideChat;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * La chat fra conducente e passeggero nel tempo: si apre solo all'accettazione, scorre
 * all'indietro, resta aperta un giorno dopo la partenza, si chiude con blocchi, sospensioni
 * e moderazione, si archivia per sé. Protegge che ognuno legga e scriva solo dove gli
 * spetta e che il doppio invio non duplichi messaggi né avvisi.
 */
require_once __DIR__.'/percorsi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('con la richiesta in attesa non c\'è chat; accettata, il passeggero la trova in elenco e scrive al conducente', function (): void {
    $ride = cpRequest($this, cpOffer($this));
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.chats'))->assertJsonCount(0, 'data.chats');
    $this->getJson(route('carpool.request', $ride))->assertJsonPath('data.ride.chat_id', null);
    $this->get(route('carpool.chats'))->assertOk()->assertSee(__('carpool.empty_messages'));

    cpAction($this, $this->driver, 'accept', ['request_id' => $ride->id])->assertOk();
    $chat = percorsoCpChat($ride);
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.request', $ride))->assertJsonPath('data.ride.chat_id', $chat->id);
    $name = $this->getJson(route('carpool.chat', $chat))->assertOk()->json('data.offer.driver.name');
    $this->get(route('carpool.chats'))->assertOk()->assertSee(route('carpool.chat', $chat), false);
    $this->get(route('carpool.chat', $chat))->assertOk()->assertSee(__('carpool.web.chat_title', ['name' => $name]));
    $this->post(route('carpool.chat.action', [$chat, 'send']), ['request_key' => (string) Str::uuid(), 'body' => 'Grazie, a sabato!'])->assertRedirect();
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.chat', $chat))->assertJsonPath('data.messages.0.body', 'Grazie, a sabato!')->assertJsonPath('data.messages.0.mine', false);
});

it('la chat si apre sugli ultimi cinquanta messaggi e «before» porta i precedenti, in ordine', function (): void {
    $ride = cpAccepted($this);
    $chat = percorsoCpChat($ride);
    $ids = collect(range(1, 55))->map(fn (int $n) => app(RideChat::class)->send($n % 2 ? $this->passenger : $this->driver, $chat, 'Messaggio '.$n, (string) Str::uuid()));

    Sanctum::actingAs($this->passenger->fresh());
    $latest = $this->getJson('/api/v1/carpool/chats/'.$chat->id)->assertOk()->assertJsonCount(50, 'data.messages')->json('data.messages');
    expect(array_column($latest, 'id'))->toBe($ids->slice(5)->values()->all())
        ->and($latest[0]['body'])->toBe('Messaggio 6')->and($latest[49]['body'])->toBe('Messaggio 55')
        ->and($latest[0]['mine'])->toBeFalse()->and($latest[49]['mine'])->toBeTrue();
    $older = $this->getJson('/api/v1/carpool/chats/'.$chat->id.'?before='.$latest[0]['id'])->assertOk()->assertJsonCount(5, 'data.messages')->json('data.messages');
    expect(array_column($older, 'body'))->toBe(['Messaggio 1', 'Messaggio 2', 'Messaggio 3', 'Messaggio 4', 'Messaggio 5']);
    $this->getJson('/api/v1/carpool/chats/'.$chat->id.'?after='.$ids[53])->assertJsonCount(1, 'data.messages')->assertJsonPath('data.messages.0.body', 'Messaggio 55');

    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.chat', $chat))->assertOk()->assertSee(__('carpool.older'))->assertSee('Messaggio 55')->assertDontSee('Messaggio 5<', false);
});

it('dopo la partenza la chat resta scrivibile per ventiquattro ore, poi diventa di sola lettura ma leggibile', function (): void {
    $ride = cpAccepted($this);
    $chat = percorsoCpChat($ride);

    freezeLocal($this->city, '2026-10-11 10:00');
    percorsoCpSend($this, $this->passenger, $chat, 'Ho dimenticato la sciarpa in auto')->assertOk();
    $this->getJson('/api/v1/carpool/chats/'.$chat->id)->assertJsonPath('data.writable', true);

    freezeLocal($this->city, '2026-10-11 20:30');
    percorsoCpSend($this, $this->driver, $chat, 'Te la porto domani')->assertForbidden()->assertJsonPath('error.message', __('carpool.errors.chat_closed'));
    $this->getJson('/api/v1/carpool/chats/'.$chat->id)->assertOk()->assertJsonPath('data.writable', false)
        ->assertJsonPath('data.messages.0.body', 'Ho dimenticato la sciarpa in auto');
    app(CarpoolLifecycle::class)->tick();
    expect($chat->fresh()->read_only_at)->not->toBeNull()->and(DB::table('chat_messages')->count())->toBe(1);
    flussoCpWeb($this, $this->driver);
    $this->get(route('carpool.chat', $chat))->assertOk()->assertSee('Ho dimenticato la sciarpa in auto')->assertSee(__('carpool.chat_readonly'));
});

it('un conducente con due passeggeri ha due conversazioni separate e ognuno legge solo la propria', function (): void {
    $offer = cpOffer($this);
    $anna = cpRequest($this, $offer);
    $bruno = carpoolPerson();
    $other = cpRequest($this, $offer, $bruno);
    cpAction($this, $this->driver, 'accept', ['request_id' => $anna->id])->assertOk();
    cpAction($this, $this->driver, 'accept', ['request_id' => $other->id])->assertOk();
    [$annaChat, $brunoChat] = [percorsoCpChat($anna), percorsoCpChat($other)];

    percorsoCpSend($this, $this->passenger, $annaChat, 'Messaggio per il conducente da Anna')->assertOk();
    percorsoCpSend($this, $bruno, $brunoChat, 'Messaggio per il conducente da Bruno')->assertOk();

    $this->getJson('/api/v1/carpool/chats')->assertJsonCount(1, 'data.chats')->assertJsonPath('data.chats.0.id', $brunoChat->id);
    $this->getJson('/api/v1/carpool/chats/'.$annaChat->id)->assertForbidden();
    percorsoCpSend($this, $bruno, $annaChat, 'Intrusione')->assertForbidden();
    Sanctum::actingAs($this->driver->fresh());
    $this->getJson('/api/v1/carpool/chats')->assertJsonCount(2, 'data.chats');
    $this->getJson('/api/v1/carpool/chats/'.$annaChat->id)->assertJsonCount(1, 'data.messages')->assertJsonPath('data.messages.0.body', 'Messaggio per il conducente da Anna');
    expect(DB::table('chat_messages')->count())->toBe(2)
        ->and($this->driver->notifications()->where('type', 'chat.message')->count())->toBe(2);
});

it('il conducente blocca il passeggero: la chat diventa di sola lettura, l\'offerta gli sparisce e lui può ancora segnalare il passaggio', function (): void {
    $ride = cpAccepted($this);
    $chat = percorsoCpChat($ride);
    percorsoCpSend($this, $this->driver, $chat, 'Messaggio prima del blocco')->assertOk();
    UserBlock::create(['user_id' => $this->driver->id, 'blocked_user_id' => $this->passenger->id]);

    expect($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled)->and($ride->fresh()->close_reason)->toBe('requirements_or_block_changed');
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.chat', $chat))->assertOk()->assertJsonPath('data.writable', false)->assertJsonPath('data.messages.0.body', 'Messaggio prima del blocco');
    percorsoCpSend($this, $this->passenger, $chat, 'Perché?')->assertForbidden();
    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.offer', $ride->offer))->assertForbidden();
    $this->getJson(route('carpool.request', $ride))->assertOk()->assertJsonPath('data.offer.zone', null);

    $this->post(route('carpool.report'), ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id, 'reason' => 'harassment', 'body' => 'Mi ha bloccato dopo un messaggio scortese'])
        ->assertRedirect();
    $case = CarpoolCase::firstOrFail();
    expect($case->ride_request_id)->toBe($ride->id)->and($case->reporter_id)->toBe($this->passenger->id);
    $this->get(route('carpool.case', $case))->assertOk();
});

it('archiviare una conversazione la sposta per sé; un nuovo messaggio la segnala da leggere e ripristinarla la riporta fra le attive', function (): void {
    $ride = cpAccepted($this);
    $chat = percorsoCpChat($ride);
    Sanctum::actingAs($this->passenger->fresh());
    $this->postJson('/api/v1/carpool/chats/'.$chat->id.'/preferences', ['archived' => true])->assertOk();
    $this->getJson('/api/v1/carpool/chats')->assertJsonCount(0, 'data.chats');

    percorsoCpSend($this, $this->driver, $chat, 'Sei ancora dei nostri?')->assertOk();
    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/chats?archived=1')->assertJsonCount(1, 'data.chats')->assertJsonPath('data.chats.0.unread', 1);
    $this->getJson('/api/v1/carpool/summary')->assertJsonPath('data.conversations', 1);

    $this->postJson('/api/v1/carpool/chats/'.$chat->id.'/preferences', ['archived' => false])->assertOk();
    $this->getJson('/api/v1/carpool/chats')->assertJsonCount(1, 'data.chats')->assertJsonPath('data.chats.0.archived', false);
    $this->getJson('/api/v1/carpool/chats?archived=1')->assertJsonCount(0, 'data.chats');
    $this->getJson('/api/v1/carpool/chats/'.$chat->id)->assertJsonCount(1, 'data.messages');
});

it('il passeggero sospeso non legge più la chat, che in elenco risulta chiusa; il conducente la trova in sola lettura', function (): void {
    $ride = cpAccepted($this);
    $chat = percorsoCpChat($ride);
    percorsoCpSend($this, $this->driver, $chat, 'Ci vediamo alle 20')->assertOk();
    $this->passenger->forceFill(['carpool_suspended_at' => now()])->save();

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.chats'))->assertJsonCount(1, 'data.chats')->assertJsonPath('data.chats.0.readable', false);
    $this->get(route('carpool.chats'))->assertOk()->assertSee(route('carpool.request', $ride), false);
    $this->get(route('carpool.chat', $chat))->assertForbidden();
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.chat', $chat))->assertOk()->assertJsonPath('data.writable', false)->assertJsonPath('data.messages.0.body', 'Ci vediamo alle 20');
    percorsoCpSend($this, $this->driver, $chat, 'Ci sei?')->assertForbidden();
    expect($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled)->and(DB::table('chat_messages')->count())->toBe(1);
});

it('lo stesso messaggio inviato due volte con la stessa chiave arriva una volta sola; testi vuoti o troppo lunghi sono respinti', function (): void {
    $ride = cpAccepted($this);
    $chat = percorsoCpChat($ride);
    $key = (string) Str::uuid();

    $id = percorsoCpSend($this, $this->passenger, $chat, 'Arrivo tra cinque minuti', $key)->assertOk()->json('data.id');
    percorsoCpSend($this, $this->passenger, $chat, 'Arrivo tra cinque minuti', $key)->assertOk()->assertJsonPath('data.id', $id);
    percorsoCpSend($this, $this->passenger, $chat, 'Arrivo tra dieci minuti', $key)->assertConflict();
    percorsoCpSend($this, $this->passenger, $chat, str_repeat('a', 2001))->assertUnprocessable();
    percorsoCpSend($this, $this->passenger, $chat, '   ')->assertUnprocessable();

    expect(DB::table('chat_messages')->count())->toBe(1)->and($this->driver->notifications()->where('type', 'chat.message')->count())->toBe(1);
    Sanctum::actingAs($this->driver->fresh());
    $this->getJson('/api/v1/carpool/chats/'.$chat->id)->assertJsonCount(1, 'data.messages')->assertJsonPath('data.messages.0.id', $id);
});

it('una chat nascosta dalla moderazione non si legge da nessuno dei due e torna leggibile quando lo staff la ripristina', function (): void {
    $ride = cpAccepted($this);
    $chat = percorsoCpChat($ride);
    percorsoCpSend($this, $this->driver, $chat, 'Testo da verificare')->assertOk();
    $case = cpCase($this, $ride);
    $admin = cpStaff();

    app(CommunitySafety::class)->freezeChat($admin, $case, true, 'Verifica in corso sul contenuto');
    foreach ([$this->passenger, $this->driver] as $person) {
        flussoCpWeb($this, $person);
        $this->get(route('carpool.chat', $chat))->assertForbidden();
        $this->getJson(route('carpool.chats'))->assertJsonPath('data.chats.0.readable', false);
    }
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.summary'))->assertJsonPath('data.conversations', 0);
    percorsoCpSend($this, $this->driver, $chat, 'Altro testo')->assertForbidden();

    app(CommunitySafety::class)->freezeChat($admin, $case->fresh(), false, 'Verifica conclusa, nulla da segnalare');
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.chat', $chat))->assertOk()->assertJsonPath('data.messages.0.body', 'Testo da verificare')->assertJsonPath('data.writable', true);
    $this->getJson(route('carpool.summary'))->assertJsonPath('data.conversations', 1);
});
