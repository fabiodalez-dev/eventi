<?php

declare(strict_types=1);

use App\Enums\FollowableType;
use App\Enums\NotificationChannel;
use App\Models\City;
use App\Models\User;
use App\Models\Venue;
use App\Services\Account\NotificationInterests;
use App\Services\Notifications\ChannelSelector;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('Test')->plainTextToken;
});

it('protects interests and synchronizes website choices through the api', function (): void {
    $this->get('/notifiche/interessi')->assertRedirect('/accedi');
    $this->getJson('/api/v1/me/notification-interests')->assertUnauthorized();
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $this->actingAs($this->user)->patch('/notifiche/interessi', ['categories' => [$this->category->id], 'venues' => [$venue->id]])->assertRedirect();
    $this->withToken($this->token)->getJson('/api/v1/me/notification-interests')->assertOk()->assertJsonPath('data.categories.0.selected', true);
    $this->withToken($this->token)->patchJson('/api/v1/me/notification-interests', ['categories' => [], 'venues' => []])->assertOk();
    expect($this->user->follows()->count())->toBe(0);
});

it('rejects private or foreign venues and never alters another users follows', function (): void {
    $foreign = City::factory()->padova()->create(['slug' => 'vicenza']);
    $venue = Venue::factory()->approved()->create(['city_id' => $foreign->id]);
    $this->withToken($this->token)->patchJson('/api/v1/me/notification-interests', ['categories' => [], 'venues' => [$venue->id]])->assertUnprocessable();
    $other = User::factory()->create();
    $other->follows()->create(['followable_type' => FollowableType::Category->value, 'followable_id' => $this->category->id, 'notify' => true]);
    app(NotificationInterests::class)->update($this->user, $this->city, ['categories' => [], 'venues' => []]);
    expect($other->follows()->count())->toBe(1);
});

it('stores delivery and time through the api while preserving reminder choices', function (): void {
    $this->user->update(['notification_preferences' => ['reminder_hours' => [12]]]);
    $this->withToken($this->token)->patchJson('/api/v1/me/notification-preferences', ['delivery' => 'both', 'daily_digest_time' => '18:30'])->assertOk()
        ->assertJsonPath('data.delivery', 'both')->assertJsonPath('data.reminder_hours', [12]);
    expect(substr($this->user->fresh()->daily_digest_time, 0, 5))->toBe('18:30');
});

it('honors explicit channels without silently emailing push-only users', function (): void {
    config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null, 'api.features.push' => false]);
    $this->user->update(['notification_preferences' => ['delivery' => 'push']]);
    expect(app(ChannelSelector::class)->for($this->user))->toBe(NotificationChannel::Database);
    config(['api.features.push' => true, 'firebase.projects.app.credentials' => '/test/configured.json']);
    $this->user->update(['notification_preferences' => ['delivery' => 'both']]);
    expect(app(ChannelSelector::class)->for($this->user))->toBe(NotificationChannel::Both);
});
