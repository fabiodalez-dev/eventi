<?php

use App\Enums\SocialPublicationStatus as Status;
use App\Filament\Admin\Pages\Social;
use App\Filament\Admin\Pages\SocialSettings;
use App\Jobs\Social\PublishSocial;
use App\Models\SocialBatch;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use App\Services\Social\SocialPublisher;
use App\Services\Social\TelegramPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->admin = $this->scenario->admin;
    $date = $this->scenario->occurrenceA;
    $this->batch = SocialBatch::create(['city_id' => $this->scenario->city->id, 'date' => $date->business_date, 'format' => 'portrait', 'user_id' => $this->admin->id,
        'options' => ['captions' => ['Gli eventi di oggi']], 'items' => [['occurrence_id' => $date->id, 'event_id' => $date->event_id, 'title' => $date->event->title, 'source_updated_at' => $date->getRawOriginal('updated_at'), 'event_updated_at' => $date->event->getRawOriginal('updated_at')]]]);
    $this->connection = SocialConnection::create(['city_id' => $this->scenario->city->id, 'telegram_bot_token' => '123:test-secret', 'telegram_chat_id' => '@incittatest', 'telegram_enabled' => true, 'telegram_verified_at' => now()]);
    Queue::fake();
    Http::preventStrayRequests();
});

it('schedules without sending and dispatches only once when due', function (): void {
    $when = CarbonImmutable::now()->addHour();
    app(SocialPublisher::class)->enqueue($this->batch, scheduledAt: $when);
    $publication = SocialPublication::firstOrFail();
    expect($publication->status)->toBe(Status::Scheduled);
    $this->artisan('social:publish-due')->assertSuccessful();
    Queue::assertNothingPushed();
    $this->travelTo($when->addSecond());
    $this->artisan('social:publish-due')->assertSuccessful();
    $this->artisan('social:publish-due')->assertSuccessful();
    Queue::assertPushed(PublishSocial::class, 1);
    expect($publication->fresh()->status)->toBe(Status::Queued);
});

it('schedules from the admin UI in the city timezone and can reschedule or cancel', function (): void {
    $this->actingAs($this->admin);
    $when = CarbonImmutable::now()->addDays(2)->setTimezone($this->scenario->city->timezone)->startOfMinute();
    $page = Livewire::test(Social::class)->call('openBatch', $this->batch->id)->set('scheduledAt', $when->format('Y-m-d\TH:i'))
        ->call('schedulePublication')->assertHasNoErrors();
    $publication = SocialPublication::firstOrFail();
    expect($publication->scheduled_at->timestamp)->toBe($when->timestamp);
    $page->set('scheduledAt', $when->addHour()->format('Y-m-d\TH:i'))->call('reschedulePublication', $publication->id)->assertHasNoErrors();
    expect($publication->fresh()->scheduled_at->timestamp)->toBe($when->addHour()->timestamp);
    $page->call('cancelScheduledPublication', $publication->id);
    $this->travelTo($when->addDay());
    $this->artisan('social:publish-due')->assertSuccessful();
    Queue::assertNothingPushed();
    expect($publication->fresh()->status)->toBe(Status::Cancelled);
});

it('rejects past schedules and unauthorized scheduling', function (): void {
    $this->actingAs($this->admin);
    Livewire::test(Social::class)->call('openBatch', $this->batch->id)->set('scheduledAt', '2000-01-01T12:00')->call('schedulePublication')->assertHasErrors('scheduledAt');
    $this->actingAs($this->scenario->plainUser)->get(Social::getUrl())->assertForbidden();
    expect(SocialPublication::count())->toBe(0);
});

it('publishes a Telegram photo once and preserves ambiguous results', function (): void {
    app(SocialPublisher::class)->enqueue($this->batch);
    $publication = SocialPublication::firstOrFail();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]])]);
    $job = new PublishSocial($publication->id);
    $job->handle(app(SocialPublisher::class));
    $job->handle(app(SocialPublisher::class));
    Http::assertSentCount(1);
    expect($publication->fresh()->status)->toBe(Status::Published)->and($publication->fresh()->external_id)->toBe('42');
});

it('does not retry Telegram automatically after a timeout', function (): void {
    app(SocialPublisher::class)->enqueue($this->batch);
    $publication = SocialPublication::firstOrFail();
    Http::fake(fn () => throw new ConnectionException('URL contains a secret-test-token'));
    $job = new PublishSocial($publication->id);
    $job->handle(app(SocialPublisher::class));
    $job->handle(app(SocialPublisher::class));
    expect($publication->fresh()->status)->toBe(Status::Uncertain)->and($publication->fresh()->error)->not->toContain('secret-test-token');
});

it('verifies Telegram permissions without sending a message and invalidates revoked access', function (): void {
    Http::fake([
        '*/getMe' => Http::sequence()->push(['ok' => true, 'result' => ['id' => 123]])->push(['ok' => false], 403),
        '*/getChat' => Http::response(['ok' => true, 'result' => ['type' => 'channel']]),
        '*/getChatMember' => Http::response(['ok' => true, 'result' => ['status' => 'administrator', 'can_post_messages' => true]]),
    ]);
    app(TelegramPublisher::class)->verify($this->connection);
    expect($this->connection->fresh()->telegram_verified_at)->not->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/send'));
    expect(fn () => app(TelegramPublisher::class)->verify($this->connection))->toThrow(RuntimeException::class);
    expect($this->connection->fresh()->telegram_verified_at)->toBeNull();
});

it('blocks stale or changed-channel posts before contacting Telegram', function (): void {
    app(SocialPublisher::class)->enqueue($this->batch);
    $publication = SocialPublication::firstOrFail();
    $this->connection->update(['telegram_chat_id' => '@different']);
    (new PublishSocial($publication->id))->handle(app(SocialPublisher::class));
    expect($publication->fresh()->status)->toBe(Status::Failed);
    Http::assertNothingSent();
});

it('never exposes stored Telegram and Meta secrets in settings', function (): void {
    $this->connection->update(['app_secret' => 'meta-secret-test']);
    expect($this->connection->toArray())->not->toHaveKeys(['telegram_bot_token', 'app_secret', 'access_token']);
    $this->actingAs($this->admin)->get(SocialSettings::getUrl())->assertOk()->assertDontSee('meta-secret-test')->assertDontSee('123:test-secret');
});

it('publishes a Telegram album with a single caption', function (): void {
    $this->batch->update(['items' => [$this->batch->items[0], $this->batch->items[0]]]);
    app(SocialPublisher::class)->enqueue($this->batch);
    Http::fake(['*/sendMediaGroup' => Http::response(['ok' => true, 'result' => [['message_id' => 50], ['message_id' => 51]]])]);
    $publication = SocialPublication::firstOrFail();
    (new PublishSocial($publication->id))->handle(app(SocialPublisher::class));
    Http::assertSent(fn ($request) => count($request['media']) === 2 && $request['media'][0]['caption'] === 'Gli eventi di oggi' && $request['media'][1]['caption'] === '');
    expect($publication->fresh()->remote_ids['messages'])->toBe([50, 51]);
});

it('rejects overlong Telegram captions before creating any publications', function (): void {
    $this->batch->update(['options' => ['captions' => [str_repeat('a', 1025)]]]);
    expect(fn () => app(SocialPublisher::class)->enqueue($this->batch))->toThrow(RuntimeException::class);
    expect(SocialPublication::count())->toBe(0);
    Queue::assertNothingPushed();
});
