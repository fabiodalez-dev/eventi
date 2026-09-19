<?php

use App\Models\CarpoolProfile;
use App\Models\RideConversation;
use App\Models\RideSearch;
use App\Notifications\CommunityPush;
use App\Services\Carpool\CarpoolLifecycle;
use App\Services\Carpool\CommunityDelivery;
use App\Services\Carpool\CommunityNotices;
use App\Services\Carpool\RideChat;
use App\Services\Carpool\UnifiedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('does not mark later notifications as read through an earlier watermark', function (): void {
    cpRequest($this, cpOffer($this));
    $notifications = app(UnifiedNotifications::class);
    $before = $notifications->watermark($this->driver);
    app(CommunityNotices::class)->send($this->driver, 'carpool', 'moderation', 'profile', $this->driver->id, 'new-notice');
    Sanctum::actingAs($this->driver);
    $this->postJson('/api/v1/me/notifications/read-all', ['through' => $before])->assertOk()->assertJsonPath('data.updated', 1);
    expect($this->driver->unreadNotifications()->count())->toBe(1);
});

it('never marks chat text read through the notification read-all button', function (): void {
    cpAccepted($this);
    $chat = RideConversation::first();
    app(RideChat::class)->send($this->driver, $chat, 'Messaggio non ancora visto', (string) Str::uuid());
    app(UnifiedNotifications::class)->readAll($this->passenger);
    expect(app(CommunityNotices::class)->summary($this->passenger)['conversations'])->toBe(1);
});

it('marks only received messages through the submitted chat watermark', function (): void {
    cpAccepted($this);
    $chat = RideConversation::first();
    $first = app(RideChat::class)->send($this->driver, $chat, 'Primo', (string) Str::uuid());
    $second = app(RideChat::class)->send($this->driver, $chat, 'Secondo', (string) Str::uuid());
    app(RideChat::class)->preferences($this->passenger, $chat, ['read_through_id' => $first]);
    expect(app(CommunityNotices::class)->summary($this->passenger)['conversations'])->toBe(1);
    app(RideChat::class)->preferences($this->passenger, $chat, ['read_through_id' => $second]);
    expect(app(CommunityNotices::class)->summary($this->passenger)['conversations'])->toBe(0);
    app(RideChat::class)->preferences($this->passenger, $chat, ['read_through_id' => $first]);
    expect(DB::table('ride_chat_preferences')->where('user_id', $this->passenger->id)->value('read_through_id'))->toBe($second);
});

it('rejects fabricated or cross-conversation read watermarks', function (): void {
    cpAccepted($this);
    $chat = RideConversation::first();
    Sanctum::actingAs($this->passenger);
    $this->postJson('/api/v1/carpool/chats/'.$chat->id.'/preferences', ['read_through_id' => 999999])->assertUnprocessable();
});

it('does not push withdrawn requests or expired acceptances', function (): void {
    $ride = cpRequest($this, cpOffer($this));
    $pending = $this->driver->notifications()->latest()->first()->data;
    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $ride->id])->assertOk();
    expect(app(CommunityDelivery::class)->allowed($this->driver, $pending))->toBeFalse();
});

it('respects per-chat mute without marking messages read', function (): void {
    cpAccepted($this);
    $chat = RideConversation::first();
    app(RideChat::class)->send($this->driver, $chat, 'Silenzioso', (string) Str::uuid());
    app(RideChat::class)->preferences($this->passenger, $chat, ['muted' => true]);
    $payload = $this->passenger->notifications()->where('data->category', 'chat')->first()->data;
    expect(app(CommunityDelivery::class)->allowed($this->passenger, $payload))->toBeFalse();
    expect(app(CommunityNotices::class)->summary($this->passenger)['conversations'])->toBe(1);
});

it('respects carpool push opt-out and global database-only preferences', function (string $preference): void {
    $ride = cpRequest($this, cpOffer($this));
    if ($preference === 'carpool') {
        CarpoolProfile::where('user_id', $this->driver->id)->update(['push_enabled' => false]);
    } else {
        $this->driver->forceFill(['notification_preferences' => ['delivery' => $preference]])->save();
    }
    $payload = $this->driver->notifications()->first()->data;
    expect(app(CommunityDelivery::class)->allowed($this->driver->fresh(), $payload))->toBeFalse();
    expect($this->driver->notifications()->count())->toBe(1);
})->with(['carpool', 'database', 'mail']);

it('does not deliver a matched search that was disabled while queued', function (): void {
    $offer = cpOffer($this);
    cpDiscovery($this, $this->passenger, 'search', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'seats' => 1, 'accessibility' => 'not_specified', 'earliest_at' => '2026-10-10T17:00:00Z', 'latest_at' => '2026-10-10T19:00:00Z', 'is_public' => false, 'alerts_enabled' => true])->assertOk();
    app(CarpoolLifecycle::class)->tick();
    $payload = $this->passenger->notifications()->where('data->kind', 'match')->firstOrFail()->data;
    expect(app(CommunityDelivery::class)->allowed($this->passenger, $payload))->toBeTrue();
    RideSearch::where('user_id', $this->passenger->id)->update(['alerts_enabled' => false]);
    expect(app(CommunityDelivery::class)->allowed($this->passenger, $payload))->toBeFalse();
});

it('keeps polling chat messages ordered and bounded without duplicates', function (): void {
    cpAccepted($this);
    $chat = RideConversation::first();
    for ($i = 0; $i < 55; $i++) {
        app(RideChat::class)->send($this->driver, $chat, 'Messaggio '.$i, (string) Str::uuid());
    }
    $latest = app(RideChat::class)->messages($this->passenger, $chat);
    expect($latest)->toHaveCount(50)->and($latest[0]['body'])->toBe('Messaggio 5')->and($latest[49]['body'])->toBe('Messaggio 54');
    $older = app(RideChat::class)->messages($this->passenger, $chat, before: $latest[0]['id']);
    expect($older)->toHaveCount(5)->and(array_intersect(array_column($older, 'id'), array_column($latest, 'id')))->toBeEmpty();
    expect(app(RideChat::class)->messages($this->passenger, $chat, after: $latest[49]['id']))->toBeEmpty();
});

it('keeps private conversation text out of FCM and Web Push payloads', function (): void {
    $push = new CommunityPush(['title' => 'Avviso', 'body' => 'SEGRETO', 'url' => 'https://example.test/passaggi', 'category' => 'chat'], 'id-notifica', 'fcm');
    expect(json_encode($push->toFcm($this->driver)->toArray()))->not->toContain('SEGRETO');
    expect(json_encode($push->toWebPush($this->driver)->toArray()))->not->toContain('SEGRETO');
});

it('keeps the in-app archive when push providers are unavailable', function (): void {
    config(['api.features.push' => false, 'webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
    cpRequest($this, cpOffer($this));
    expect(app(CommunityDelivery::class)->deliver())->toBe(1)->and($this->driver->unreadNotifications()->count())->toBe(1);
});

it('defers pushes during the recipient quiet hours without consuming retries', function (): void {
    config(['notifications.quiet_hours.enabled' => true]);
    $this->driver->forceFill(['quiet_hours' => ['from' => '11:00', 'to' => '13:00'], 'timezone' => 'Europe/Rome'])->save();
    cpRequest($this, cpOffer($this));
    app(CommunityDelivery::class)->deliver();
    $row = DB::table('community_delivery_outbox')->first();
    expect($row->delivered_at)->toBeNull()->and($row->attempts)->toBe(0)->and($row->available_at)->toBe('2026-10-01 11:00:00');
});

it('regenerates notification destinations using the active domain', function (): void {
    $url = app(UnifiedNotifications::class)->destination(['category' => 'carpool', 'entity' => 'request', 'entity_id' => 42, 'url' => 'https://old.example/passaggi/richieste/42']);
    expect($url['url'])->toBe(route('carpool.request', 42));
});

it('does not count expired chat history in unread conversation badges', function (): void {
    cpAccepted($this);
    $chat = RideConversation::first();
    app(RideChat::class)->send($this->driver, $chat, 'Vecchio messaggio', (string) Str::uuid());
    $chat->update(['read_only_at' => now()->subDays(91)]);
    expect(app(CommunityNotices::class)->summary($this->passenger)['conversations'])->toBe(0);
});
