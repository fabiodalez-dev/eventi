<?php

declare(strict_types=1);

use App\DTOs\NotificationMessage;
use App\Enums\FollowableType;
use App\Enums\NotificationSkipReason;
use App\Enums\NotificationType;
use App\Filament\Admin\Pages\Newsletter;
use App\Jobs\Middleware\RespectNotificationPreferences;
use App\Models\NotificationText;
use App\Models\ScheduledNotification;
use App\Models\User;
use App\Notifications\Scheduled\ScheduledMessage;
use App\Services\Notifications\MessageFactory;
use App\Settings\NewsletterSettings;
use App\Support\Features;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 12:00');
    (new RolesAndPermissionsSeeder)->run();
    $this->user = User::factory()->marketingOptedIn()->create(['content_preferences' => ['categories' => [$this->category->id]]]);
    $this->date = occurrenceAtLocal($this->city, $this->category, '2026-09-12 21:00');
});

it('offers newsletter settings with an icon only to superadmins and saves validated text', function (): void {
    $this->actingAs($this->user)->get('/admin/newsletter')->assertForbidden();
    $this->user->assignRole('admin');
    expect(Newsletter::canAccess())->toBeFalse();
    $this->user->assignRole('super_admin');
    $this->get('/admin/newsletter')->assertOk()->assertSee('Newsletter');
    expect(Newsletter::getNavigationIcon())->toBe(Heroicon::OutlinedEnvelope);
    Livewire::test(Newsletter::class)->fillForm(['enabled' => true, 'weekday' => 5, 'time' => '18:45', 'max_items' => 3, 'subject' => 'Il tuo weekend', 'heading' => 'Eventi scelti per te', 'line' => 'Scopri :count eventi', 'action' => 'Scopri il weekend'])->call('save')->assertHasNoFormErrors();
    expect(app(NewsletterSettings::class)->time)->toBe('18:45')
        ->and(NotificationText::where('key', 'notifications.weekend.subject')->value('value'))->toBe('Il tuo weekend');
    Livewire::test(Newsletter::class)->fillForm(['line' => 'Variabile :inesistente'])->call('save')->assertHasFormErrors(['line']);
    Livewire::test(Newsletter::class)->fillForm(['weekday' => 9, 'time' => '25:00', 'max_items' => 0])->call('save')->assertHasFormErrors(['weekday', 'time', 'max_items']);
});

it('selects only recipient interests including followed venues and excludes hidden categories', function (): void {
    $other = testCategory(['slug' => 'altro']);
    occurrenceAtLocal($this->city, $other, '2026-09-12 22:00');
    $row = new ScheduledNotification(['type' => NotificationType::WeekendNewsletter->value]);
    $message = app(MessageFactory::class)->build($row, $this->user);
    expect($message)->toBeInstanceOf(NotificationMessage::class)->and($message->items)->toHaveCount(1)
        ->and($message->items[0]['title'])->toBe($this->date->event->title);
    $this->user->update(['content_preferences' => ['hidden_categories' => [$this->category->id]]]);
    $this->user->follows()->create(['followable_type' => FollowableType::Venue->value, 'followable_id' => $this->date->event->venue_id, 'notify' => true]);
    expect(app(MessageFactory::class)->build($row, $this->user))->toBe(NotificationSkipReason::NothingToSend);
    $this->user->update(['content_preferences' => []]);
    expect(app(MessageFactory::class)->build($row, $this->user)->items)->toHaveCount(1);
    $this->user->follows()->delete();
    expect(app(MessageFactory::class)->build($row, $this->user))->toBe(NotificationSkipReason::NothingToSend);
});

it('uses the configured schedule and stops pending newsletters when disabled', function (): void {
    Notification::fake();
    $settings = app(NewsletterSettings::class);
    $settings->weekday = 4;
    $settings->time = '18:45';
    $settings->save();
    $this->artisan('notifications:plan')->assertSuccessful();
    $row = ScheduledNotification::ofType(NotificationType::WeekendNewsletter->value)->sole();
    expect($row->send_at->timezone('Europe/Rome')->format('H:i'))->toBe('18:45');
    Feature::for(Features::globalScope())->deactivate(Features::NEWSLETTER);
    freezeLocal($this->city, '2026-09-10 19:00');
    $this->artisan('notifications:send')->assertSuccessful();
    expect($row->fresh()->last_error)->toBe('preference_off');
    Notification::assertNothingSent();
});

it('rechecks quiet hours and revoked consent when a queued delivery actually runs', function (): void {
    $message = app(MessageFactory::class)->build(new ScheduledNotification(['type' => NotificationType::WeekendNewsletter->value]), $this->user);
    $notification = new ScheduledMessage($message);
    $job = (new SendQueuedNotifications($this->user, $notification, ['mail']))->withFakeQueueInteractions();
    freezeLocal($this->city, '2026-09-10 23:30');
    $called = false;
    $middleware = new RespectNotificationPreferences;
    $middleware->handle($job, function () use (&$called): void {
        $called = true;
    });
    $job->assertReleased();
    expect($called)->toBeFalse();
    freezeLocal($this->city, '2026-09-11 08:00');
    $middleware->handle($job, function () use (&$called): void {
        $called = true;
    });
    expect($called)->toBeTrue();
    $this->user->update(['marketing_opt_in_at' => null]);
    $called = false;
    $middleware->handle($job, function () use (&$called): void {
        $called = true;
    });
    expect($called)->toBeFalse();
});
