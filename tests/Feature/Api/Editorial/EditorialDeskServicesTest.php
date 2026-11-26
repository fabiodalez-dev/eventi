<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\Permission;
use App\Enums\StatsPeriod;
use App\Enums\UserRole;
use App\Filament\Admin\Pages\Carpool;
use App\Filament\Admin\Resources\CatalogReviews\CatalogReviewResource;
use App\Filament\Admin\Resources\EventComments\EventCommentResource;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Admin\Resources\EventSubmissions\EventSubmissionResource;
use App\Filament\Admin\Resources\Reports\ReportResource;
use App\Filament\Admin\Resources\ScheduledNotifications\ScheduledNotificationResource;
use App\Filament\Admin\Resources\SponsorshipOrders\SponsorshipOrderResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\VenueApplications\VenueApplicationResource;
use App\Filament\Admin\Resources\Venues\VenueResource;
use App\Models\City;
use App\Models\Event;
use App\Models\Sponsorship;
use App\Services\Analytics\EventAnalyticsDashboard;
use App\Services\Analytics\EventShareReport;
use App\Services\Carpool\CommunitySafety;
use App\Services\Sponsorship\SponsorshipReport;
use App\Support\Editorial\EditorialBadges;
use App\Support\Editorial\EditorialDesk;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\EditorialSession;
use Tests\Support\VenueIsolationScenario;

/*
 * I servizi che chiedevano a Filament «sono nella redazione?» adesso lo
 * chiedono a `EditorialDesk` (§3.1): nel pannello rispondono come prima,
 * dall'API rispondono con il banco legato dalla richiesta, e fuori da
 * entrambi non danno più a un admin un permesso implicito.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
});

it('ricava il banco dal pannello admin, e solo da quello', function (): void {
    $this->actingAs($this->s->admin);

    expect(EditorialDesk::current())->toBeNull();

    Filament::setCurrentPanel('venue');
    expect(EditorialDesk::current())->toBeNull();

    Filament::setCurrentPanel('admin');
    $desk = EditorialDesk::current();
    expect($desk?->role)->toBe(UserRole::Admin)
        ->and($desk?->user->is($this->s->admin))->toBeTrue()
        ->and($desk?->city?->is($this->s->city))->toBeTrue()
        ->and(EditorialDesk::boundToRequest())->toBeFalse();
});

it('sceglie il ruolo più alto e la città legata dall’API', function (): void {
    $both = EditorialSession::user(UserRole::Moderator);
    $both->assignRole(UserRole::SuperAdmin->value);
    $other = City::factory()->create(['slug' => 'rovigo', 'is_active' => false]);

    expect(EditorialDesk::for($both)?->role)->toBe(UserRole::SuperAdmin)
        ->and(EditorialDesk::for($this->s->ownerA))->toBeNull()
        ->and(EditorialDesk::for($this->s->plainUser))->toBeNull();

    $this->actingAs($this->s->moderator);
    EditorialDesk::bind(app('request'), EditorialDesk::for($this->s->moderator, $other));
    expect(EditorialDesk::current()?->city?->is($other))->toBeTrue()
        ->and(EditorialDesk::boundToRequest())->toBeTrue()
        ->and(EditorialDesk::current()?->isAdmin())->toBeFalse();
});

it('dà le statistiche di tutti a admin e super admin della redazione dall’API, e le nega al moderatore', function (): void {
    $this->actingAs($this->s->admin);
    EditorialDesk::bind(app('request'), EditorialDesk::for($this->s->admin));

    $events = app(EventAnalyticsDashboard::class)->events()->pluck('id')->all();
    expect($events)->toContain($this->s->publishedEventA->id, $this->s->publishedEventB->id)
        ->and(app(EventAnalyticsDashboard::class)->timezone())->toBe('Europe/Rome')
        ->and(app(EventShareReport::class)->rows(StatsPeriod::cases()[0])->total())->toBe(0);

    $this->actingAs($this->s->moderator);
    EditorialDesk::bind(app('request'), EditorialDesk::for($this->s->moderator));
    expect(fn () => app(EventAnalyticsDashboard::class)->events())->toThrow(HttpException::class);
});

it('dà tutte le campagne solo dentro la redazione, non a un admin qualunque', function (): void {
    $campaign = Sponsorship::factory()->create(['event_id' => $this->s->publishedEventA->id]);
    $this->actingAs($this->s->admin);

    // Fuori dal pannello e senza banco: prima bastava il ruolo, adesso no.
    expect(fn () => app(SponsorshipReport::class)->campaigns())->toThrow(HttpException::class);

    Filament::setCurrentPanel('admin');
    expect(app(SponsorshipReport::class)->campaigns()->pluck('id')->all())->toContain($campaign->id);

    Filament::setCurrentPanel('venue');
    EditorialDesk::bind(app('request'), EditorialDesk::for($this->s->admin));
    expect(app(SponsorshipReport::class)->campaigns()->pluck('id')->all())->toContain($campaign->id);

    $this->actingAs($this->s->moderator);
    EditorialDesk::bind(app('request'), EditorialDesk::for($this->s->moderator));
    expect(fn () => app(SponsorshipReport::class)->campaigns())->toThrow(HttpException::class);
});

it('fa passare CommunitySafety::requireStaff da una richiesta senza sessione', function (): void {
    $token = EditorialSession::token($this->s->moderator);
    EditorialSession::as($this, $token)->getJson('/api/v1/editorial/desk')->assertOk();

    expect(request()->hasSession())->toBeFalse();
    app(CommunitySafety::class)->requireStaff($this->s->moderator, Permission::ManageCommunity);
    expect(fn () => app(CommunitySafety::class)->requireStaff($this->s->moderator, Permission::ReadCommunityMessages, sensitive: true))->toThrow(HttpException::class)
        ->and(fn () => app(CommunitySafety::class)->requireStaff($this->s->plainUser, Permission::ManageCommunity))->toThrow(HttpException::class);
});

it('conta come il menu del pannello, con le stesse chiavi di cache', function (): void {
    Event::factory()->count(2)->create(['city_id' => $this->s->city->id, 'category_id' => $this->s->category->id, 'venue_id' => $this->s->venueA->id, 'status' => EventStatus::Pending]);
    Cache::flush();
    $this->actingAs($this->s->admin);
    Filament::setCurrentPanel('admin');

    $badges = app(EditorialBadges::class)->for($this->s->admin);
    $panel = [
        'events_pending' => EventResource::getNavigationBadge(),
        'event_submissions_pending' => EventSubmissionResource::getNavigationBadge(),
        'venues_pending' => VenueResource::getNavigationBadge(),
        'venue_applications_pending' => VenueApplicationResource::getNavigationBadge(),
        'users_registrations' => UserResource::getNavigationBadge(),
        'reports_open' => ReportResource::getNavigationBadge(),
        'event_comments_hidden' => EventCommentResource::getNavigationBadge(),
        'catalog_reviews_pending' => CatalogReviewResource::getNavigationBadge(),
        'carpool_open' => Carpool::getNavigationBadge(),
        'sponsorship_orders_awaiting' => SponsorshipOrderResource::getNavigationBadge(),
        'scheduled_notifications_due' => ScheduledNotificationResource::getNavigationBadge(),
    ];

    expect(array_keys($panel))->toBe(EditorialBadges::KEYS)
        ->and($badges['events_pending'])->toBe(2)
        ->and(Cache::get('nav-badge:events-pending'))->toBe(2);
    foreach ($panel as $key => $value) {
        expect($badges[$key])->toBe((int) $value, $key);
    }
});
