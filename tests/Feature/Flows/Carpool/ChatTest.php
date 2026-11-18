<?php

declare(strict_types=1);

use App\Models\RideConversation;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * La chat del passaggio: si apre con l'accettazione, si legge e si scrive dal sito e
 * dall'app, ha preferenze personali (letto fino a, silenzio, archivio) e resta chiusa a chi
 * non partecipa.
 */
require_once __DIR__.'/flussi-carpool.php';

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('passeggero e conducente si scrivono, leggono, silenziano e archiviano la conversazione', function (): void {
    $ride = cpAccepted($this);
    $chat = RideConversation::where('ride_request_id', $ride->id)->firstOrFail();

    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.chats'))->assertOk();
    $this->getJson(route('carpool.chats'))->assertJsonCount(1, 'data.chats')->assertJsonPath('data.chats.0.id', $chat->id)
        ->assertJsonPath('data.chats.0.readable', true)->assertJsonPath('data.chats.0.unread', 0);
    $this->get(route('carpool.chat', $chat))->assertOk()->assertSee(__('carpool.chat_context'));
    $this->getJson(route('carpool.chat', $chat))->assertJsonPath('data.writable', true)->assertJsonPath('data.messages', []);

    $this->from(route('carpool.chat', $chat))->post(route('carpool.chat.action', [$chat, 'send']), ['request_key' => (string) Str::uuid(), 'body' => 'Ciao, dove ci troviamo?'])
        ->assertRedirect(route('carpool.chat', $chat))->assertSessionHas('status');
    expect(DB::table('chat_messages')->count())->toBe(1)->and(DB::table('chat_messages')->value('body'))->not->toContain('dove ci troviamo')
        ->and($this->driver->notifications()->where('type', 'chat.message')->count())->toBe(1);
    // Un messaggio vuoto non parte.
    $this->postJson(route('carpool.chat.action', [$chat, 'send']), ['request_key' => (string) Str::uuid(), 'body' => ''])->assertUnprocessable();

    Sanctum::actingAs($this->driver->fresh());
    $this->getJson('/api/v1/carpool/chats')->assertOk()->assertJsonPath('data.chats.0.unread', 1);
    $messages = $this->getJson('/api/v1/carpool/chats/'.$chat->id)->assertOk()->assertJsonPath('data.messages.0.body', 'Ciao, dove ci troviamo?')
        ->assertJsonPath('data.messages.0.mine', false)->json('data.messages');
    $reply = $this->postJson('/api/v1/carpool/chats/'.$chat->id.'/send', ['request_key' => (string) Str::uuid(), 'body' => 'Davanti alla basilica alle 20'])->assertOk()->json('data.id');
    $this->postJson('/api/v1/carpool/chats/'.$chat->id.'/preferences', ['read_through_id' => $messages[0]['id'], 'muted' => true])->assertOk();
    $this->getJson('/api/v1/carpool/chats')->assertJsonPath('data.chats.0.unread', 0);
    $this->getJson('/api/v1/carpool/chats/'.$chat->id)->assertJsonPath('data.muted', true)->assertJsonCount(2, 'data.messages');
    // Il polling restituisce solo i messaggi nuovi.
    $this->getJson('/api/v1/carpool/chats/'.$chat->id.'?after='.$messages[0]['id'])->assertJsonCount(1, 'data.messages')->assertJsonPath('data.messages.0.id', $reply);

    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.chat', $chat))->assertOk()->assertSee('Davanti alla basilica alle 20');
    $this->post(route('carpool.chat.action', [$chat, 'preferences']), ['archived' => 1])->assertRedirect();
    $this->getJson(route('carpool.chats'))->assertJsonCount(0, 'data.chats');
    $this->getJson(route('carpool.chats').'?archived=1')->assertJsonCount(1, 'data.chats')->assertJsonPath('data.chats.0.archived', true);
    // L'archivio è personale: il conducente la vede ancora fra le attive.
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.chats'))->assertJsonCount(1, 'data.chats');
});

it('la chat altrui è negata a chi non partecipa e diventa di sola lettura quando il passaggio salta', function (): void {
    $ride = cpAccepted($this);
    $chat = RideConversation::firstOrFail();
    cpAction($this, carpoolPerson(), 'request', ['offer_id' => $ride->ride_offer_id, 'revision' => 1, 'seats' => 1])->assertOk();
    $stranger = carpoolPerson();

    flussoCpWeb($this, $stranger);
    $this->getJson(route('carpool.chats'))->assertOk()->assertJsonCount(0, 'data.chats');
    $this->get(route('carpool.chat', $chat))->assertForbidden();
    $this->post(route('carpool.chat.action', [$chat, 'send']), ['request_key' => (string) Str::uuid(), 'body' => 'Intruso'])->assertForbidden();
    $this->post(route('carpool.chat.action', [$chat, 'preferences']), ['archived' => 1])->assertForbidden();
    Sanctum::actingAs($stranger->fresh());
    $this->getJson('/api/v1/carpool/chats/'.$chat->id)->assertForbidden();
    $this->postJson('/api/v1/carpool/chats/'.$chat->id.'/send', ['request_key' => (string) Str::uuid(), 'body' => 'Intruso'])->assertForbidden();
    expect(DB::table('chat_messages')->count())->toBe(0)
        ->and(DB::table('ride_chat_preferences')->where('user_id', $stranger->id)->count())->toBe(0);

    // Chi ha solo una richiesta in attesa sulla stessa offerta non ha chat.
    $this->getJson('/api/v1/carpool/chats/999999')->assertNotFound();

    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $ride->id])->assertOk();
    Sanctum::actingAs($this->driver->fresh());
    $this->getJson('/api/v1/carpool/chats/'.$chat->id)->assertOk()->assertJsonPath('data.writable', false);
    $this->postJson('/api/v1/carpool/chats/'.$chat->id.'/send', ['request_key' => (string) Str::uuid(), 'body' => 'Peccato'])->assertForbidden()
        ->assertJsonPath('error.message', __('carpool.errors.chat_closed'));
    expect(DB::table('chat_messages')->count())->toBe(0);
});
