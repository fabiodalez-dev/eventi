<?php

declare(strict_types=1);

use App\DTOs\QuietHours;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\Venue;
use App\Services\Ticketing\TicketingService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ImageFixtures;

beforeEach(function (): void {
    $this->city = testCity();
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('Android')->plainTextToken;
});

it('saves native newsletter consent and quiet hours used by the delivery engine', function (): void {
    $this->withToken($this->token)->patchJson('/api/v1/me/notification-preferences', [
        'marketing_opt_in' => true,
        'quiet_hours' => ['from' => '22:30', 'to' => '07:15'],
        'reminder_hours' => [24, 1],
    ])->assertOk()->assertJsonPath('data.marketing_opt_in', true)
        ->assertJsonPath('data.quiet_hours_effective.from', '22:30');
    $user = $this->user->fresh();
    $consent = $user->marketing_opt_in_at;
    $quiet = QuietHours::fromUser($user);
    expect($quiet->contains(CarbonImmutable::parse('2026-09-12 01:00', $user->timezone)))->toBeTrue()
        ->and($quiet->endsAfter(CarbonImmutable::parse('2026-09-12 01:00', $user->timezone))->format('H:i'))->toBe('07:15');

    $this->withToken($this->token)->patchJson('/api/v1/me/notification-preferences', ['marketing_opt_in' => true])->assertOk();
    expect($this->user->fresh()->marketing_opt_in_at->equalTo($consent))->toBeTrue();
    $this->withToken($this->token)->patchJson('/api/v1/me/notification-preferences', ['marketing_opt_in' => false, 'quiet_hours' => []])
        ->assertOk()->assertJsonPath('data.quiet_hours_effective', null)->assertJsonPath('data.marketing_opt_in', false);
    $this->withToken($this->token)->patchJson('/api/v1/me/notification-preferences', ['quiet_hours' => null])
        ->assertOk()->assertJsonPath('data.quiet_hours', null)->assertJsonPath('data.quiet_hours_effective.from', '23:00');
});

it('rejects incomplete quiet hours without partially saving consent', function (): void {
    $this->withToken($this->token)->patchJson('/api/v1/me/notification-preferences', [
        'marketing_opt_in' => true, 'quiet_hours' => ['from' => '25:00'],
    ])->assertUnprocessable();
    expect($this->user->fresh()->marketing_opt_in_at)->toBeNull();
});

it('exposes the uploaded venue logo separately from its cover', function (): void {
    Queue::fake();
    Storage::fake('public');
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'is_nonprofit' => true]);
    $logo = $venue->addMedia(ImageFixtures::upload('logo.png', ImageFixtures::png(600, 240)))->toMediaCollection('logo');
    $this->getJson('/api/v1/venues/'.$venue->slug)->assertOk()
        ->assertJsonPath('data.logo.full', $logo->getFullUrl())
        ->assertJsonPath('data.cover', null)->assertJsonPath('data.is_nonprofit', true);
});

it('offers newsletter management only to the superadmin', function (UserRole $role): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->user->assignRole($role);
    $links = $this->withToken($this->token)->getJson('/api/v1/me')->assertOk()->json('data.management_links');
    expect(collect($links)->where('icon', 'newsletter')->pluck('url')->all())
        ->toBe($role === UserRole::SuperAdmin ? [url('/admin/newsletter')] : []);
    if ($role === UserRole::User) {
        expect($links)->toBe([]);
    }
})->with([UserRole::User, UserRole::Admin, UserRole::SuperAdmin]);

it('returns the effective event end so ongoing tickets stay in upcoming', function (): void {
    Notification::fake();
    $day = now('Europe/Rome')->addDay()->format('Y-m-d');
    $date = occurrenceAtLocal($this->city, testCategory(), $day.' 21:00', $day.' 23:00', [
        'booking_enabled' => true, 'booking_capacity' => 5, 'booking_limit' => 5,
    ]);
    $date->event->venue->update(['ticketing_enabled' => true]);
    app(TicketingService::class)->reserve($this->user, $date, ['Anna Rossi'], (string) Str::uuid(), false);
    $this->withToken($this->token)->getJson('/api/v1/me/bookings')->assertOk()
        ->assertJsonPath('data.0.ends_at', $date->fresh()->effective_ends_at->toIso8601String());
});
