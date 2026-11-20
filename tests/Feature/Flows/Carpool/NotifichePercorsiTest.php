<?php

declare(strict_types=1);

use App\Models\RideSearch;
use App\Models\User;
use App\Notifications\CommunityPush;
use App\Services\Carpool\CarpoolLifecycle;
use App\Services\Carpool\CommunityDelivery;
use App\Services\Carpool\CommunitySafety;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * Gli avvisi dei passaggi dal fatto alla notifica push: chi la riceve, con quale
 * collegamento, quando non parte (richiesta ritirata, push spenti, chat silenziata),
 * promemoria e solleciti senza doppioni, e che ogni collegamento apra davvero una pagina
 * per chi lo riceve. Le push passano da Notification::fake.
 */
require_once __DIR__.'/percorsi-carpool.php';

beforeEach(function (): void {
    cpSetup($this);
    config(['webpush.vapid.public_key' => 'chiave-pubblica-di-prova', 'webpush.vapid.private_key' => 'chiave-privata-di-prova',
        'api.features.push' => false, 'notifications.quiet_hours.enabled' => false]);
    Notification::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** I tipi delle push consegnate a una persona, nell'ordine. */
function percorsoCpPushed(User $user): array
{
    return Notification::sent($user, CommunityPush::class)->map(fn (CommunityPush $push) => $push->payload['kind'])->values()->all();
}

it('la richiesta arriva come push al conducente con il collegamento alla richiesta, l\'accettazione al passeggero, e nessuno riceve le proprie', function (): void {
    $ride = cpRequest($this, cpOffer($this));
    app(CommunityDelivery::class)->deliver();

    Notification::assertSentTo($this->driver, CommunityPush::class, fn (CommunityPush $push): bool => $push->payload['kind'] === 'requested'
        && $push->payload['url'] === route('carpool.request', $ride) && ! str_contains(json_encode($push->payload), 'Prato'));
    Notification::assertNotSentTo($this->passenger, CommunityPush::class);

    cpAction($this, $this->driver, 'accept', ['request_id' => $ride->id])->assertOk();
    app(CommunityDelivery::class)->deliver();
    app(CommunityDelivery::class)->deliver();
    expect(percorsoCpPushed($this->passenger))->toBe(['accepted'])->and(percorsoCpPushed($this->driver))->toBe(['requested'])
        ->and(DB::table('community_delivery_outbox')->whereNull('delivered_at')->count())->toBe(0);
    flussoCpWeb($this, $this->passenger);
    $this->get(Notification::sent($this->passenger, CommunityPush::class)->first()->payload['url'])->assertOk();
});

it('una richiesta ritirata prima della consegna non parte come push ma resta fra gli avvisi del conducente', function (): void {
    $ride = cpRequest($this, cpOffer($this));
    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $ride->id])->assertOk();
    app(CommunityDelivery::class)->deliver();

    expect(percorsoCpPushed($this->driver))->toBe(['withdrawn'])
        ->and($this->driver->notifications()->pluck('type')->sort()->values()->all())->toBe(['carpool.requested', 'carpool.withdrawn'])
        ->and(DB::table('community_delivery_outbox')->where('user_id', $this->driver->id)->whereNotNull('delivered_at')->count())->toBe(2);
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.summary'))->assertJsonPath('data.unread', 2)->assertJsonPath('data.pending', 0);
});

it('il passeggero che spegne le push dei passaggi trova gli avvisi solo nella pagina, e riaccese tornano', function (): void {
    $ride = cpRequest($this, cpOffer($this));
    percorsoCpPost($this, $this->passenger, 'preferences', ['push_enabled' => '0'])->assertRedirect(route('carpool.requirements'));
    cpAction($this, $this->driver, 'accept', ['request_id' => $ride->id])->assertOk();
    app(CommunityDelivery::class)->deliver();

    Notification::assertNotSentTo($this->passenger, CommunityPush::class);
    expect($this->passenger->notifications()->where('type', 'carpool.accepted')->count())->toBe(1);

    percorsoCpPost($this, $this->passenger, 'preferences', ['push_enabled' => '1'])->assertRedirect(route('carpool.requirements'));
    percorsoCpSend($this, $this->driver, percorsoCpChat($ride), 'Partiamo puntuali')->assertOk();
    app(CommunityDelivery::class)->deliver();
    expect(percorsoCpPushed($this->passenger))->toBe(['message']);
    Notification::assertSentTo($this->passenger, CommunityPush::class, fn (CommunityPush $push): bool => ! str_contains(json_encode($push->payload), 'Partiamo'));
});

it('la chat silenziata non manda push dei messaggi ma li conta da leggere; tolto il silenzio i nuovi tornano push', function (): void {
    $ride = cpAccepted($this);
    $chat = percorsoCpChat($ride);
    flussoCpWeb($this, $this->driver);
    $this->post(route('carpool.chat.action', [$chat, 'preferences']), ['muted' => '1'])->assertRedirect();
    percorsoCpSend($this, $this->passenger, $chat, 'Primo messaggio')->assertOk();
    app(CommunityDelivery::class)->deliver();

    // La richiesta era già accettata alla consegna: al conducente non parte nulla, nemmeno il messaggio.
    expect(percorsoCpPushed($this->driver))->toBe([]);
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.summary'))->assertJsonPath('data.conversations', 1);
    $this->getJson(route('carpool.chat', $chat))->assertJsonPath('data.muted', true);

    $this->post(route('carpool.chat.action', [$chat, 'preferences']), ['muted' => '0'])->assertRedirect();
    percorsoCpSend($this, $this->passenger, $chat, 'Secondo messaggio')->assertOk();
    app(CommunityDelivery::class)->deliver();
    expect(percorsoCpPushed($this->driver))->toBe(['message']);
});

it('i promemoria arrivano a conducente e passeggero il giorno prima e due ore prima, una volta sola ciascuno', function (): void {
    $ride = cpAccepted($this);
    $reminders = fn (User $user): int => $user->notifications()->where('type', 'carpool.reminder')->count();

    freezeLocal($this->city, '2026-10-09 21:00');
    app(CarpoolLifecycle::class)->tick();
    app(CarpoolLifecycle::class)->tick();
    expect($reminders($this->passenger))->toBe(1)->and($reminders($this->driver))->toBe(1);

    freezeLocal($this->city, '2026-10-10 18:30');
    app(CarpoolLifecycle::class)->tick();
    app(CarpoolLifecycle::class)->tick();
    expect($reminders($this->passenger))->toBe(2)->and($reminders($this->driver))->toBe(2);

    app(CommunityDelivery::class)->deliver();
    expect(array_count_values(percorsoCpPushed($this->passenger))['reminder'])->toBe(2)
        ->and(array_count_values(percorsoCpPushed($this->driver))['reminder'])->toBe(2);
    flussoCpWeb($this, $this->passenger);
    $this->get($this->passenger->notifications()->where('type', 'carpool.reminder')->first()->data['url'])->assertOk()->assertSee('data-request-progress', false);
    expect($ride->fresh()->status->value)->toBe('accepted');
});

it('il conducente con una richiesta ferma da un giorno riceve un sollecito al giorno, che smette quando decide', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer);
    $reminders = fn (): int => $this->driver->notifications()->where('type', 'carpool.pending')->count();

    freezeLocal($this->city, '2026-10-09 21:00');
    app(CarpoolLifecycle::class)->tick();
    app(CarpoolLifecycle::class)->tick();
    expect($reminders())->toBe(1);
    app(CommunityDelivery::class)->deliver();
    Notification::assertSentTo($this->driver, CommunityPush::class, fn (CommunityPush $push): bool => $push->payload['kind'] === 'pending'
        && $push->payload['url'] === route('carpool.offer', $offer));

    freezeLocal($this->city, '2026-10-10 09:00');
    app(CarpoolLifecycle::class)->tick();
    expect($reminders())->toBe(2);
    cpAction($this, $this->driver, 'decline', ['request_id' => $ride->id])->assertOk();
    freezeLocal($this->city, '2026-10-10 10:00');
    app(CarpoolLifecycle::class)->tick();
    app(CommunityDelivery::class)->deliver();
    expect($reminders())->toBe(2)->and(array_count_values(percorsoCpPushed($this->driver))['pending'])->toBe(1);
});

it('la risposta dell\'assistenza arriva come push con il collegamento al caso, che il passeggero apre', function (): void {
    $ride = cpAccepted($this);
    $case = cpCase($this, $ride);
    app(CommunitySafety::class)->manage(cpStaff(), $case, 'reply', 'Ci servono altri dettagli', ['revision' => $case->revision, 'recipient_id' => $this->passenger->id]);
    app(CommunityDelivery::class)->deliver();

    Notification::assertSentTo($this->passenger, CommunityPush::class, fn (CommunityPush $push): bool => $push->payload['kind'] === 'support'
        && $push->payload['url'] === route('carpool.case', $case));
    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.case', $case))->assertOk()->assertSee('Ci servono altri dettagli');
    Notification::assertNotSentTo($this->driver, CommunityPush::class, fn (CommunityPush $push): bool => $push->payload['kind'] === 'support');
});

it('ogni avviso dei passaggi porta a una pagina che chi lo riceve può aprire', function (): void {
    $searcher = carpoolPerson();
    $search = cpDiscovery($this, $searcher, 'search', percorsoCpSearch($this, ['is_public' => true]))->assertOk()->json('data.id');
    $offer = cpOffer($this);
    cpDiscovery($this, $this->driver, 'suggest', ['search_id' => $search, 'offer_id' => $offer->id])->assertOk();
    app(CarpoolLifecycle::class)->tick();
    $ride = cpRequest($this, $offer, seats: 2);
    cpAction($this, $this->driver, 'accept', ['request_id' => $ride->id])->assertOk();
    cpAction($this, $this->passenger, 'reduce', ['request_id' => $ride->id, 'seats' => 1])->assertOk();
    percorsoCpSend($this, $this->driver, percorsoCpChat($ride), 'A dopo')->assertOk();
    $other = cpOffer($this, carpoolPerson(), ['leg' => 'return', 'zone' => 'Centro', 'departure_at' => '2026-10-10T23:00:00Z']);
    $back = cpRequest($this, $other);
    cpAction($this, $other->driver, 'cancel', ['offer_id' => $other->id])->assertOk();

    $seen = [];
    foreach ([$searcher, $this->driver, $this->passenger] as $person) {
        foreach ($person->notifications()->get() as $notice) {
            flussoCpWeb($this, $person);
            $this->get($notice->data['url'])->assertOk();
            $seen[] = $notice->type;
        }
    }
    expect(array_unique($seen))->toEqualCanonicalizing(['carpool.suggestion', 'carpool.match', 'carpool.requested', 'carpool.accepted', 'carpool.reduced', 'chat.message', 'carpool.cancelled'])
        ->and(RideSearch::findOrFail($search)->active)->toBeTrue()->and($back->fresh()->status->value)->toBe('cancelled');
});
