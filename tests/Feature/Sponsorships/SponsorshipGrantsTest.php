<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\SponsorshipGrants\Pages\CreateGrant;
use App\Filament\Venue\Pages\Sponsorships;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\User;
use App\Models\Venue;
use App\Services\Sponsorship\GrantCampaigns;
use App\Services\Sponsorship\SponsorshipSelector;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->venue = Venue::factory()->approved()->create();
    $this->owner = User::factory()->create();
    $this->owner->assignRole(UserRole::VenueOwner->value);
    $this->owner->venues()->attach($this->venue, ['role' => 'owner']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
    $this->event = Event::factory()->create(['venue_id' => $this->venue->id, 'city_id' => $this->venue->city_id, 'status' => EventStatus::Published]);
    $this->grant = SponsorshipGrant::create(['venue_id' => $this->venue->id, 'created_by' => $this->admin->id,
        'mode' => 'selected', 'placement' => 'list_top', 'enabled' => true, 'complimentary' => false,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'amount_cents' => 10000]);
});

it('requires payment or explicit complimentary authorization and expires without cron', function (): void {
    EventOccurrence::factory()->create(['event_id' => $this->event->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    expect(SponsorshipGrant::active()->count())->toBe(0);
    $this->grant->update(['paid_at' => now()]);
    $campaign = app(GrantCampaigns::class)->choose($this->owner, $this->grant, $this->event);
    expect(Sponsorship::visible()->whereKey($campaign->id)->exists())->toBeTrue();
    $this->grant->update(['enabled' => false]);
    expect(Sponsorship::visible()->whereKey($campaign->id)->exists())->toBeFalse();
    $this->grant->update(['enabled' => true, 'paid_at' => null, 'complimentary' => true]);
    expect(Sponsorship::visible()->whereKey($campaign->id)->exists())->toBeTrue();
    $this->travelTo($this->grant->ends_at);
    expect(Sponsorship::visible()->whereKey($campaign->id)->exists())->toBeFalse();
});

it('keeps venue selection isolated and unpaid grants unusable', function (): void {
    $this->actingAs($this->owner);
    expect(fn () => app(GrantCampaigns::class)->choose($this->owner, $this->grant, $this->event))->toThrow(HttpException::class);
    $this->grant->update(['complimentary' => true]);
    $foreign = Event::factory()->create();
    expect(fn () => app(GrantCampaigns::class)->choose($this->owner, $this->grant, $foreign))->toThrow(AuthorizationException::class);
    expect(Gate::forUser($this->owner)->allows('create', SponsorshipGrant::class))->toBeFalse();
    expect(Gate::forUser($this->admin)->allows('create', SponsorshipGrant::class))->toBeTrue();
    expect(Gate::forUser($this->owner)->allows('feature', $this->event))->toBeFalse();
});

it('automatically includes new events without duplicates and hides drafts', function (): void {
    $this->grant->update(['mode' => 'automatic', 'complimentary' => true]);
    $service = app(GrantCampaigns::class);
    $service->sync($this->grant);
    $service->sync($this->grant);
    expect($this->grant->sponsorships()->count())->toBe(1);
    $draft = Event::factory()->create(['venue_id' => $this->venue->id, 'status' => EventStatus::Draft]);
    $service->sync($this->grant);
    expect($this->grant->sponsorships()->count())->toBe(2);
    expect(Sponsorship::visible()->where('event_id', $draft->id)->exists())->toBeFalse();
});

it('renders a private draft preview with dates without making the public event accessible', function (): void {
    $this->event->update(['status' => EventStatus::Draft]);
    EventOccurrence::factory()->create(['event_id' => $this->event->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    $url = route('events.preview', $this->event);
    $this->get($url)->assertRedirect();
    $this->actingAs($this->owner)->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee(__('promotions.preview_notice'));
    $this->get(route('events.show', $this->event))->assertNotFound();
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get($url)->assertForbidden();
});

it('renders admin payment forms and the venue summary with historic metrics', function (): void {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    $this->get('/admin/sponsorship-grants')->assertOk();
    $this->get('/admin/sponsorship-grants/create')->assertOk();
    $this->get('/admin/sponsorship-grants/'.$this->grant->id.'/edit')->assertOk();
    $this->actingAs($this->owner);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
    Livewire::test(Sponsorships::class)->assertSee(__('promotions.awaiting_payment'));
});

it('records a manual payment in cents through the admin form', function (): void {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    Livewire::test(CreateGrant::class)
        ->fillForm(['venue_id' => $this->venue->id, 'mode' => 'automatic', 'placement' => 'home_card',
            'enabled' => true, 'complimentary' => false, 'starts_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addMonth()->format('Y-m-d H:i:s'), 'amount_cents' => '19.99',
            'paid_at' => now()->subDay()->format('Y-m-d H:i:s'), 'payment_method' => 'Bonifico', 'payment_reference' => 'DEMO-001'])
        ->call('create')->assertHasNoFormErrors();
    $grant = SponsorshipGrant::where('payment_reference', 'DEMO-001')->firstOrFail();
    expect($grant->amount_cents)->toBe(1999)->and($grant->sponsorships()->count())->toBe(1);
});

it('lets a paid venue select and stop its campaign through the panel', function (): void {
    $this->grant->update(['paid_at' => now()->subMinute()]);
    $this->actingAs($this->owner);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
    $page = Livewire::test(Sponsorships::class)
        ->callAction('choose', data: ['grant' => $this->grant->id, 'event' => $this->event->id])
        ->assertHasNoActionErrors();
    $campaign = $this->grant->sponsorships()->firstOrFail();
    $page->call('stop', $campaign->id);
    expect($campaign->fresh()->status)->toBe(SponsorshipStatus::Paused);
});

it('does not select automatic campaigns for events without upcoming dates', function (): void {
    $this->grant->update(['complimentary' => true, 'mode' => 'automatic']);
    app(GrantCampaigns::class)->sync($this->grant);
    $selector = app(SponsorshipSelector::class);
    expect($selector->forPlacement($this->venue->city, SponsorshipPlacement::ListTop))->toHaveCount(0);
    EventOccurrence::factory()->create(['event_id' => $this->event->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    expect($selector->forPlacement($this->venue->city, SponsorshipPlacement::ListTop))->toHaveCount(1);
});
