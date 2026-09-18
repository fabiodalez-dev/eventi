<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Events\RelationManagers\ActivityRelationManager;
use App\Filament\Admin\Resources\SponsorshipGrants\Pages\CreateGrant;
use App\Filament\Admin\Resources\SponsorshipGrants\Pages\EditGrant;
use App\Filament\Venue\Pages\Sponsorships;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\User;
use App\Models\Venue;
use App\Services\Sponsorship\GrantCampaigns;
use App\Services\Sponsorship\GrantPayment;
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

it('hides every payment field for complimentary grants and refuses an empty reason', function (): void {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    $page = Livewire::test(CreateGrant::class)->fillForm([
        'venue_id' => $this->venue->id, 'mode' => 'selected', 'placement' => 'list_top',
        'complimentary' => true, 'starts_at' => now()->subDay()->format('Y-m-d H:i:s'),
        'ends_at' => now()->addMonth()->format('Y-m-d H:i:s'), 'notes' => '',
        'amount_cents' => '99.99', 'paid_at' => now()->subDay()->format('Y-m-d H:i:s'),
        'payment_method' => 'Bonifico', 'payment_reference' => 'NON-DEVE-RESTARE',
    ]);
    foreach (GrantPayment::FIELDS as $field) {
        $page->assertFormFieldIsHidden($field);
    }
    $page->call('create')->assertHasFormErrors(['notes' => 'required']);
    $page->fillForm(['notes' => 'Patrocinio culturale'])->call('create')->assertHasNoFormErrors();
    $grant = SponsorshipGrant::latest('id')->firstOrFail();
    foreach (GrantPayment::FIELDS as $field) {
        expect($grant->{$field})->toBeNull();
    }
    expect($grant->created_by)->toBe($this->admin->id);
});

it('clears a previous payment when making a grant free and retains its audit history', function (): void {
    $this->grant->update(['paid_at' => now()->subHour(), 'payment_method' => 'Bonifico', 'payment_reference' => 'RICEVUTA-STORICA']);
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    $page = Livewire::test(EditGrant::class, ['record' => $this->grant->id])
        ->set('data.complimentary', true)
        ->fillForm(['notes' => 'Concessione culturale gratuita'])
        ->call('save')->assertHasNoFormErrors();
    foreach (GrantPayment::FIELDS as $field) {
        expect($this->grant->fresh()->{$field})->toBeNull();
    }
    expect($this->grant->activitiesAsSubject()->latest('id')->first()->attribute_changes['old']['payment_reference'])->toBe('RICEVUTA-STORICA');
    Livewire::test(ActivityRelationManager::class, [
        'ownerRecord' => $this->grant->fresh(),
        'pageClass' => EditGrant::class,
    ])->assertSee('RICEVUTA-STORICA')->assertSee(__('promotions.reference'));
    $page->set('data.complimentary', false)->fillForm(['amount_cents' => '25.00'])->call('save')->assertHasNoFormErrors();
    expect($this->grant->fresh()->statusLabel())->toBe(__('promotions.awaiting_payment'));
});

it('rejects ambiguous money amounts and invalid date windows', function (array $invalid, string $field): void {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    Livewire::test(CreateGrant::class)->fillForm(array_replace([
        'venue_id' => $this->venue->id, 'mode' => 'selected', 'placement' => 'list_top',
        'complimentary' => false, 'starts_at' => now()->subDay()->format('Y-m-d H:i:s'),
        'ends_at' => now()->addMonth()->format('Y-m-d H:i:s'), 'amount_cents' => '19.99',
    ], $invalid))->call('create')->assertHasFormErrors([$field]);
})->with([
    [['amount_cents' => '19.999'], 'amount_cents'],
    [['amount_cents' => '0'], 'amount_cents'],
    [['paid_at' => '2099-01-01 12:00:00'], 'paid_at'],
    [['ends_at' => '2000-01-01 12:00:00'], 'ends_at'],
]);

it('keeps month durations aligned with their start and respects a manual end date', function (): void {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    Livewire::test(CreateGrant::class)
        ->set('data.starts_at', '2027-01-31 12:00:00')->set('data.months', 1)
        ->assertSet('data.ends_at', '2027-02-28 12:00')
        ->set('data.months', 3)->assertSet('data.ends_at', '2027-04-30 12:00')
        ->set('data.months', 1)
        ->set('data.starts_at', '2027-03-31 12:00:00')
        ->assertSet('data.ends_at', '2027-04-30 12:00')
        ->set('data.ends_at', '2027-07-10 12:00:00')
        ->assertFormSet(['months' => null])
        ->set('data.starts_at', '2027-04-15 12:00:00')
        ->assertSet('data.ends_at', '2027-07-10 12:00:00');
});

it('does not allow form edits to move a grant to another venue or change its mode and placement', function (): void {
    $foreign = Venue::factory()->approved()->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    Livewire::test(EditGrant::class, ['record' => $this->grant->id])
        ->fillForm(['venue_id' => $foreign->id, 'mode' => 'automatic', 'placement' => 'home_card'])
        ->call('save')->assertHasNoFormErrors();
    $grant = $this->grant->fresh();
    expect($grant->venue_id)->toBe($this->venue->id)
        ->and($grant->mode->value)->toBe('selected')->and($grant->placement->value)->toBe('list_top');
});

it('blocks non-admin access to grant creation and editing', function (string $role): void {
    $user = $role === 'owner' ? $this->owner : User::factory()->create();
    if ($role === 'moderator') {
        $user->assignRole(UserRole::Moderator->value);
    }
    $this->actingAs($user);
    $this->get('/admin/sponsorship-grants/create')->assertForbidden();
    $this->get('/admin/sponsorship-grants/'.$this->grant->id.'/edit')->assertForbidden();
})->with(['owner', 'moderator']);

it('updates campaign dates without reactivating a campaign stopped by the venue', function (): void {
    $this->grant->update(['complimentary' => true]);
    $service = app(GrantCampaigns::class);
    $campaign = $service->choose($this->owner, $this->grant, $this->event);
    $campaign->update(['status' => SponsorshipStatus::Paused]);
    $this->grant->update(['ends_at' => now()->addMonths(3)]);
    $service->sync($this->grant);
    expect($campaign->fresh()->status)->toBe(SponsorshipStatus::Paused)
        ->and($campaign->fresh()->ends_at->equalTo($this->grant->ends_at))->toBeTrue();
});

it('keeps historical activity entries in the older storage format readable', function (): void {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    activity()->performedOn($this->grant)->withProperties([
        'attributes' => ['notes' => 'Nota archivio precedente'],
        'old' => ['notes' => 'Motivazione originale conservata'],
    ])->log('updated');
    Livewire::test(ActivityRelationManager::class, [
        'ownerRecord' => $this->grant,
        'pageClass' => EditGrant::class,
    ])->assertSee('Nota archivio precedente')->assertSee('Motivazione originale conservata');
});
