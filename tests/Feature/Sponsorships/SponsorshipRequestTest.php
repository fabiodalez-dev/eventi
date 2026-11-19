<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\SponsorshipOrders\Pages\ListOrders;
use App\Filament\Admin\Widgets\SponsorshipHealthWidget;
use App\Filament\Organizer\Pages\Sponsorships as OrganizerSponsorships;
use App\Filament\Venue\Pages\SponsorshipAnalytics as VenueAnalytics;
use App\Filament\Venue\Pages\Sponsorships as VenueSponsorships;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
use App\Models\SponsorshipGrant;
use App\Models\SponsorshipOrder;
use App\Models\SponsorshipPrice;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\SponsorshipOrderDecided;
use App\Notifications\SponsorshipOrderRequested;
use App\Services\Sponsorship\Payment\PaymentGateway;
use App\Services\Sponsorship\SponsorshipOrders;
use App\Services\Sponsorship\SponsorshipReport;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FakePaymentGateway;

/**
 * La strada senza Stripe: dal pannello si chiede una sponsorizzazione con le
 * stesse scelte dell'acquisto, la redazione la riceve e la approva
 * registrando il pagamento a mano o concedendola gratis. Finché non approva
 * non si attiva niente.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
    $this->gateway = new FakePaymentGateway;
    $this->gateway->configured = false;
    app()->instance(PaymentGateway::class, $this->gateway);

    $this->city = testCity();
    $this->category = testCategory();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Circolo Aurora']);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(UserRole::VenueOwner->value);
    $this->owner->venues()->attach($this->venue, ['role' => 'owner']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
    $this->event = srEvent($this->venue, 'Concerto del circolo', now()->addDays(3));
    $this->price = SponsorshipPrice::create(['placement' => 'home_hero', 'months' => 1, 'amount_cents' => 15000, 'currency' => 'EUR', 'is_active' => true]);
});

function srEvent(Venue $venue, string $title, ?DateTimeInterface $date, EventStatus $status = EventStatus::Published, ?Organizer $organizer = null): Event
{
    $event = Event::factory()->create(['venue_id' => $venue->id, 'city_id' => $venue->city_id, 'category_id' => test()->category->id,
        'organizer_id' => $organizer?->id, 'status' => $status, 'published_at' => now()->subDay(), 'title' => $title]);
    if ($date !== null) {
        EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => $date, 'ends_at' => (clone $date)->modify('+2 hours')]);
    }

    return $event;
}

function srVenuePage(): void
{
    test()->actingAs(test()->owner)->get('/gestione/'.test()->venue->slug.'/sponsorizzazioni')->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant(test()->venue);
}

function srAdminPanel(): void
{
    test()->actingAs(test()->admin)->get('/admin/sponsorship-orders')->assertOk();
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
}

it('explains the page and sends a request without Stripe, which activates nothing until approved', function (): void {
    srVenuePage();
    Livewire::test(VenueSponsorships::class)
        ->assertSee(__('promotions.page.how_title'))
        ->assertSee(__('promotions.page.how_step_3_request'))
        ->assertSee('Concerto del circolo')
        ->call('selectEvent', $this->event->id)
        ->assertSet('eventId', $this->event->id)
        ->call('selectPlacement', 'home_hero')
        ->assertSet('duration', 'price:'.$this->price->id)
        ->assertSee(__('promotions.page.submit_request'))
        ->assertDontSee(__('promotions.page.submit_online'))
        ->set('note', 'Preferisco pagare con bonifico.')
        ->call('submitOrder')
        ->assertNotified(__('promotions.request.sent'))
        ->assertSet('eventId', null)
        ->assertSee(__('promotions.orders.status.requested'))
        ->assertSee(__('promotions.request.withdraw'));

    $order = SponsorshipOrder::sole();
    expect($order->status)->toBe(SponsorshipOrderStatus::Requested)
        ->and($order->event_id)->toBe($this->event->id)
        ->and($order->mode)->toBe(PromotionMode::Selected)
        ->and($order->placement)->toBe(SponsorshipPlacement::HomeHero)
        ->and($order->months)->toBe(1)
        ->and($order->amount_cents)->toBe(15000)
        ->and($order->request_note)->toBe('Preferisco pagare con bonifico.')
        ->and($order->checkout_session_id)->toBeNull()
        ->and($this->gateway->checkouts)->toBe([])
        ->and(SponsorshipGrant::count())->toBe(0)
        ->and(Sponsorship::count())->toBe(0);
    Notification::assertSentTo($this->admin, SponsorshipOrderRequested::class, function (SponsorshipOrderRequested $mail) {
        $text = implode(' ', $mail->toMail($this->admin)->introLines);

        return str_contains($text, 'Circolo Aurora') && str_contains($text, 'Concerto del circolo') && str_contains($text, 'bonifico');
    });
    Notification::assertNotSentTo($this->owner, SponsorshipOrderRequested::class);
});

it('offers only published events with an upcoming date and checks them again on the server', function (): void {
    $draft = srEvent($this->venue, 'Bozza segreta', now()->addDays(3), EventStatus::Draft);
    $past = srEvent($this->venue, 'Serata passata', now()->subDays(3));
    $undated = srEvent($this->venue, 'Senza date', null);
    $foreignVenue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $foreign = srEvent($foreignVenue, 'Evento di un altro locale', now()->addDays(3));

    srVenuePage();
    $page = Livewire::test(VenueSponsorships::class)
        ->assertSee('Concerto del circolo')
        ->assertDontSee('Bozza segreta')->assertDontSee('Serata passata')->assertDontSee('Senza date')->assertDontSee('Evento di un altro locale');

    foreach ([$draft, $past, $undated, $foreign] as $event) {
        $page->call('selectEvent', $event->id)->assertNotified(__('promotions.request.event_not_available'))->assertSet('eventId', null);
    }

    // Un valore forzato nella richiesta Livewire non passa lo stesso.
    $page->set('eventId', $draft->id)->call('selectPlacement', 'home_hero')->call('submitOrder')->assertNotified(__('promotions.request.incomplete'));
    expect(SponsorshipOrder::count())->toBe(0);

    $service = app(SponsorshipOrders::class);
    foreach ([$draft, $past, $foreign] as $event) {
        expect(fn () => $service->request($this->owner, $this->venue, SponsorshipPlacement::HomeHero, 1, $this->price, PromotionMode::Selected, $event))
            ->toThrow(HttpException::class);
    }
    // L'evento si indica solo con «eventi scelti», e la durata deve essere quella del listino.
    expect(fn () => $service->request($this->owner, $this->venue, SponsorshipPlacement::HomeHero, 1, $this->price, PromotionMode::Automatic, $this->event))->toThrow(HttpException::class)
        ->and(fn () => $service->request($this->owner, $this->venue, SponsorshipPlacement::HomeHero, 3, $this->price, PromotionMode::Selected, $this->event))->toThrow(HttpException::class)
        ->and(fn () => $service->request($this->owner, $this->venue, SponsorshipPlacement::ListTop, 1, $this->price, PromotionMode::Selected, $this->event))->toThrow(HttpException::class);
    expect(SponsorshipOrder::count())->toBe(0);
});

it('arrives with the event already chosen from a link, only if it can be sponsored', function (): void {
    $draft = srEvent($this->venue, 'Bozza segreta', now()->addDays(3), EventStatus::Draft);
    srVenuePage();
    Livewire::withQueryParams(['event' => $this->event->id])->test(VenueSponsorships::class)->assertSet('eventId', $this->event->id);
    Livewire::withQueryParams(['event' => $draft->id])->test(VenueSponsorships::class)->assertSet('eventId', null);
});

it('marks events that are already sponsored and lets the owner choose an active grant event in one tap', function (): void {
    Sponsorship::factory()->create(['event_id' => $this->event->id, 'city_id' => $this->city->id, 'status' => SponsorshipStatus::Active,
        'starts_at' => now()->subDay(), 'ends_at' => now()->addWeek(), 'placement' => SponsorshipPlacement::ListTop]);

    srVenuePage();
    Livewire::test(VenueSponsorships::class)->assertSee(__('promotions.page.already'))->assertDontSee(__('promotions.page.active_title'));
});

it('lets the newsroom approve a request with a manual payment and puts the chosen event in evidence', function (): void {
    $order = app(SponsorshipOrders::class)->request($this->owner, $this->venue, SponsorshipPlacement::HomeHero, 1, $this->price, PromotionMode::Selected, $this->event, null);

    srAdminPanel();
    Livewire::test(ListOrders::class)->assertCanSeeTableRecords([$order])
        ->assertTableActionHidden('approve', $order)
        ->assertTableActionVisible('approveRequest', $order)
        ->callTableAction('approveRequest', $order, ['settlement' => 'paid', 'amount' => '', 'payment_method' => 'bank_transfer'])
        ->assertHasTableActionErrors(['amount']);
    expect($order->fresh()->status)->toBe(SponsorshipOrderStatus::Requested);
    Livewire::test(ListOrders::class)
        ->callTableAction('approveRequest', $order, ['settlement' => 'paid', 'amount' => '150.00', 'payment_method' => 'bank_transfer', 'payment_reference' => 'BONIFICO-42'])
        ->assertHasNoTableActionErrors();

    $order->refresh();
    $grant = $order->grant;
    expect($order->status)->toBe(SponsorshipOrderStatus::Approved)
        ->and($grant->complimentary)->toBeFalse()
        ->and($grant->amount_cents)->toBe(15000)
        ->and($grant->payment_method)->toBe('bank_transfer')
        ->and($grant->payment_reference)->toBe('BONIFICO-42')
        ->and(SponsorshipGrant::active()->whereKey($grant->id)->exists())->toBeTrue();
    $campaign = Sponsorship::query()->where('sponsorship_grant_id', $grant->id)->sole();
    expect($campaign->event_id)->toBe($this->event->id)
        ->and(Sponsorship::visible()->whereKey($campaign->id)->exists())->toBeTrue();
    Notification::assertSentTo($this->owner, SponsorshipOrderDecided::class);
});

it('lets the newsroom approve a request for free only with a reason, and reject one without promising a refund', function (): void {
    $service = app(SponsorshipOrders::class);
    $free = $service->request($this->owner, $this->venue, SponsorshipPlacement::HomeHero, 1, $this->price, PromotionMode::Automatic, null, null);

    srAdminPanel();
    Livewire::test(ListOrders::class)
        ->callTableAction('approveRequest', $free, ['settlement' => 'free', 'reason' => ''])
        ->assertHasTableActionErrors(['reason']);
    Livewire::test(ListOrders::class)
        ->callTableAction('approveRequest', $free, ['settlement' => 'free', 'reason' => 'Patrocinio della rassegna'])
        ->assertHasNoTableActionErrors();
    $grant = $free->fresh()->grant;
    expect($grant->complimentary)->toBeTrue()
        ->and($grant->amount_cents)->toBeNull()
        ->and($grant->notes)->toContain('Patrocinio della rassegna')
        ->and($grant->sponsorships()->where('event_id', $this->event->id)->exists())->toBeTrue();

    $rejected = $service->request($this->owner, $this->venue, SponsorshipPlacement::HomeHero, 1, $this->price, PromotionMode::Automatic, null, null);
    Livewire::test(ListOrders::class)->callTableAction('reject', $rejected, ['reason' => 'Posizione già venduta'])->assertHasNoTableActionErrors();
    expect($rejected->fresh()->status)->toBe(SponsorshipOrderStatus::Rejected)
        ->and($this->gateway->refunds)->toBe([]);
    Notification::assertSentTo($this->owner, SponsorshipOrderDecided::class, function (SponsorshipOrderDecided $mail) use ($rejected): bool {
        $lines = implode(' ', $mail->toMail($this->owner)->introLines);

        return str_contains($lines, __('promotions.orders.mail.rejected_request_line', ['placement' => $rejected->placement->label(), 'months' => 1]));
    });
});

it('lets the owner withdraw a request and caps how many can wait at once', function (): void {
    srVenuePage();
    $page = Livewire::test(VenueSponsorships::class);
    foreach (range(1, SponsorshipOrders::MAX_OPEN_REQUESTS) as $i) {
        $page->set('scope', 'all')->call('selectPlacement', 'home_hero')->call('submitOrder')->assertNotified(__('promotions.request.sent'));
    }
    $page->set('scope', 'all')->call('selectPlacement', 'home_hero')->call('submitOrder')
        ->assertNotified(__('promotions.request.too_many', ['max' => SponsorshipOrders::MAX_OPEN_REQUESTS]));
    expect(SponsorshipOrder::count())->toBe(SponsorshipOrders::MAX_OPEN_REQUESTS);

    $first = SponsorshipOrder::query()->oldest('id')->first();
    $page->call('withdraw', $first->id)->assertNotified(__('promotions.request.withdrawn'));
    expect($first->fresh()->status)->toBe(SponsorshipOrderStatus::Cancelled);

    // Un collaboratore non chiede e non ritira.
    $editor = User::factory()->create();
    $editor->assignRole(UserRole::VenueEditor->value);
    $editor->venues()->attach($this->venue, ['role' => 'editor']);
    $this->actingAs($editor)->get('/gestione/'.$this->venue->slug.'/sponsorizzazioni')->assertOk();
    Livewire::test(VenueSponsorships::class)->assertSee(__('promotions.page.not_owner_title'))
        ->call('withdraw', SponsorshipOrder::query()->latest('id')->first()->id)->assertForbidden();
});

it('proposes fixed durations with the price to agree when the price list is empty', function (): void {
    $this->price->update(['is_active' => false]);
    srVenuePage();
    Livewire::test(VenueSponsorships::class)
        ->set('scope', 'all')->call('selectPlacement', 'map_sheet')
        ->assertSet('duration', 'months:1')
        ->assertSee(__('promotions.request.price_to_agree'))
        ->call('selectDuration', 'months:3')->call('submitOrder')->assertNotified(__('promotions.request.sent'));
    $order = SponsorshipOrder::sole();
    expect($order->months)->toBe(3)->and($order->amount_cents)->toBe(0)->and($order->sponsorship_price_id)->toBeNull()
        ->and($order->mode)->toBe(PromotionMode::Automatic);
    // Una durata inventata non passa.
    expect(fn () => app(SponsorshipOrders::class)->request($this->owner, $this->venue, SponsorshipPlacement::MapSheet, 5, null, PromotionMode::Automatic, null))
        ->toThrow(HttpException::class);
});

it('lets an organization request a sponsorship and see only its own campaigns in the report', function (): void {
    $orgOwner = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->city->id, 'owner_id' => $orgOwner->id, 'is_active' => true]);
    $orgEvent = srEvent($this->venue, 'Serata del collettivo', now()->addDays(4), organizer: $organizer);
    $mine = Sponsorship::factory()->create(['event_id' => $orgEvent->id, 'city_id' => $this->city->id]);
    $other = Sponsorship::factory()->create(['event_id' => $this->event->id, 'city_id' => $this->city->id]);

    $this->actingAs($orgOwner)->get('/organizza/'.$organizer->slug.'/sponsorizzazioni')->assertOk()
        ->assertSee(__('promotions.page.how_title'));
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organizer);
    Livewire::test(OrganizerSponsorships::class)
        ->assertSee('Serata del collettivo')->assertDontSee('Concerto del circolo')
        ->call('selectEvent', $orgEvent->id)->call('selectPlacement', 'home_hero')->call('submitOrder')
        ->assertNotified(__('promotions.request.sent'));
    expect(SponsorshipOrder::sole()->organizer_id)->toBe($organizer->id);

    $ids = app(SponsorshipReport::class)->campaigns()->pluck('id')->all();
    expect($ids)->toBe([$mine->id])->not->toContain($other->id);
});

it('reads days and end dates in the city time zone', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-10 22:30:00', 'UTC'));
    $campaign = Sponsorship::factory()->create(['event_id' => $this->event->id, 'city_id' => $this->city->id, 'status' => SponsorshipStatus::Active,
        'starts_at' => now()->subDays(3), 'ends_at' => CarbonImmutable::parse('2026-07-20 21:59:00', 'UTC'), 'placement' => SponsorshipPlacement::ListTop]);
    // A Padova è già l'11 luglio: il contatore di quel giorno deve entrare nella settimana.
    SponsorshipDailyStat::query()->create(['sponsorship_id' => $campaign->id, 'day' => '2026-07-11', 'impressions' => 7, 'clicks' => 2]);
    SponsorshipDailyStat::query()->create(['sponsorship_id' => $campaign->id, 'day' => '2026-07-01', 'impressions' => 100, 'clicks' => 9]);

    srVenuePage();
    Livewire::test(VenueSponsorships::class)->assertSee('20/07/2026 23:59')->assertDontSee('20/07/2026 21:59');

    $report = Livewire::test(VenueAnalytics::class)->set('filters.days', '7')->instance()->report();
    expect($report['impressions'])->toBe(7)->and($report['series']->last()['date'])->toBe('sab 11 lug');
    $all = Livewire::test(VenueAnalytics::class)->set('filters.days', 'all')->instance()->report();
    expect($all['impressions'])->toBe(107)->and($all['series']->first()['date'])->toBe('mer 1 lug');
    $custom = Livewire::test(VenueAnalytics::class)->set('filters.days', 'custom')->set('filters.from', '2026-07-01')->set('filters.to', '2026-07-02')->instance()->report();
    expect($custom['impressions'])->toBe(100)->and($custom['series'])->toHaveCount(2);
});

it('counts in the admin summary only campaigns that really show, in the dashboard city', function (): void {
    $past = srEvent($this->venue, 'Serata passata', now()->subDays(3));
    $otherCity = City::factory()->create(['slug' => 'vicenza-test', 'timezone' => 'Europe/Rome']);
    $otherVenue = Venue::factory()->approved()->create(['city_id' => $otherCity->id]);
    $elsewhere = srEvent($otherVenue, 'Altrove', now()->addDays(2));
    $window = ['status' => SponsorshipStatus::Active, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(5), 'placement' => SponsorshipPlacement::ListTop];
    Sponsorship::factory()->create(['event_id' => $this->event->id, 'city_id' => $this->city->id, ...$window]);
    Sponsorship::factory()->create(['event_id' => $past->id, 'city_id' => $this->city->id, ...$window]);
    Sponsorship::factory()->create(['event_id' => $elsewhere->id, 'city_id' => $otherCity->id, ...$window]);

    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    $widget = Livewire::test(SponsorshipHealthWidget::class)->instance();
    $stats = (fn () => $this->getStats())->call($widget);
    /** @var list<Stat> $stats */
    expect((string) $stats[0]->getValue())->toBe('1')
        ->and((string) $stats[1]->getValue())->toBe('1');
});
