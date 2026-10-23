<?php

declare(strict_types=1);

use App\Actions\Account\FollowSubject;
use App\Actions\PublishVenueNotice;
use App\Enums\FollowableType;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Enums\VenueNoticeKind;
use App\Filament\Venue\Pages\VenueProfile;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\Scheduled\ScheduledMessage;
use App\Services\Notifications\MessageFactory;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

afterEach(fn () => Carbon\Carbon::setTestNow());

it('reaches notifying followers once, shows on the public page until the date, and is capped weekly', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $city = testCity();
    freezeLocal($city, '2026-10-23 12:00');
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $owner = User::factory()->create();
    $owner->assignRole(UserRole::VenueOwner->value);
    $venue->members()->attach($owner, ['role' => 'owner']);
    $follower = User::factory()->create(['email_verified_at' => now()]);
    $muted = User::factory()->create(['email_verified_at' => now()]);
    app(FollowSubject::class)($follower, FollowableType::Venue, $venue->id);
    app(FollowSubject::class)($muted, FollowableType::Venue, $venue->id, false);

    $notice = app(PublishVenueNotice::class)->handle($venue, $owner, VenueNoticeKind::Pause, "Chiusi per ferie.\nSi riapre il 10.", CarbonImmutable::parse('2026-11-10'));
    $row = ScheduledNotification::query()->ofType('venue_notice')->sole();
    expect($row->user_id)->toBe($follower->id)
        ->and($venue->fresh()->currentNotice()?->is($notice))->toBeTrue();
    $message = app(MessageFactory::class)->build($row, $follower);
    expect($message->type)->toBe(NotificationType::VenueNotice)
        ->and($message->url)->toBe(route('venues.show', $venue))
        ->and($message->lines[0])->toContain('Chiusi per ferie');
    Notification::fake();
    $this->artisan('notifications:send')->assertSuccessful();
    Notification::assertSentTo($follower, ScheduledMessage::class);
    Notification::assertNotSentTo($muted, ScheduledMessage::class);

    $this->get(route('venues.show', $venue))->assertOk()->assertSee('Chiusi per ferie')->assertSee('Pausa');
    expect(fn () => app(PublishVenueNotice::class)->handle($venue, $owner, VenueNoticeKind::Info, 'Altro', null))->toThrow(ValidationException::class);

    freezeLocal($city, '2026-11-11 12:00');
    expect($venue->fresh()->currentNotice())->toBeNull();
    $this->get(route('venues.show', $venue))->assertOk()->assertDontSee('Chiusi per ferie');
});

it('is published from the venue panel by the owner', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $venue = Venue::factory()->approved()->create(['city_id' => testCity()->id]);
    $owner = User::factory()->create();
    $owner->assignRole(UserRole::VenueOwner->value);
    $venue->members()->attach($owner, ['role' => 'owner']);
    $this->actingAs($owner);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($venue, true);
    Livewire::test(VenueProfile::class)
        ->callAction('notify_followers', ['kind' => 'reopening', 'body' => 'Si riapre sabato con la nuova gestione.', 'until' => null])
        ->assertHasNoActionErrors();
    expect($venue->notices()->count())->toBe(1)
        ->and($venue->notices()->first()->kind)->toBe(VenueNoticeKind::Reopening);
});
