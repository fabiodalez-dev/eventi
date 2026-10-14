<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\SponsorshipPlacement;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\SponsorshipOrders\Pages\ListOrders;
use App\Filament\Organizer\Pages\Sponsorships as OrganizerSponsorships;
use App\Filament\Venue\Pages\Sponsorships as VenueSponsorships;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\SponsorshipOrder;
use App\Models\SponsorshipPrice;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\SponsorshipOrderDecided;
use App\Notifications\SponsorshipOrderPaid;
use App\Services\Sponsorship\GrantCampaigns;
use App\Services\Sponsorship\Payment\PaymentGateway;
use App\Services\Sponsorship\SponsorshipOrders;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FakePaymentGateway;

/**
 * Il percorso intero di una sponsorizzazione comprata dal pannello: acquisto,
 * webhook di Stripe, approvazione della redazione, evento in cima a `/eventi`
 * con l'etichetta e il nome di chi paga.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
    config(['services.stripe.secret' => 'sk_test_fake', 'services.stripe.webhook_secret' => 'whsec_test_fake']);
    $this->gateway = new FakePaymentGateway;
    app()->instance(PaymentGateway::class, $this->gateway);

    $this->city = testCity();
    $this->category = testCategory();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Circolo Aurora']);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(UserRole::VenueOwner->value);
    $this->owner->venues()->attach($this->venue, ['role' => 'owner']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
    $this->event = Event::factory()->create(['venue_id' => $this->venue->id, 'city_id' => $this->city->id, 'category_id' => $this->category->id,
        'status' => EventStatus::Published, 'published_at' => now(), 'title' => 'Concerto del circolo']);
    EventOccurrence::factory()->create(['event_id' => $this->event->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2)]);
    $this->price = SponsorshipPrice::create(['placement' => 'list_top', 'months' => 3, 'amount_cents' => 16200, 'currency' => 'EUR', 'is_active' => true]);
});

/** @return array{0: string, 1: string} corpo e intestazione firmata come fa Stripe */
function stripeSignedEvent(string $sessionId, string $intent, string $type = 'checkout.session.completed', string $secret = 'whsec_test_fake'): array
{
    $payload = json_encode(['id' => 'evt_'.$sessionId, 'object' => 'event', 'type' => $type, 'created' => time(), 'api_version' => '2024-06-20',
        'data' => ['object' => ['id' => $sessionId, 'object' => 'checkout.session', 'payment_status' => 'paid', 'payment_intent' => $intent]]], JSON_THROW_ON_ERROR);
    $timestamp = time();

    return [$payload, 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret)];
}

function postStripeWebhook(string $payload, string $signature)
{
    return test()->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload);
}

function venuePanel(): void
{
    test()->actingAs(test()->owner);
    Filament::setCurrentPanel('venue');
    Filament::setTenant(test()->venue);
}

function adminPanel(): void
{
    test()->actingAs(test()->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
}

it('takes a venue from purchase to a sponsored event visible on the public list', function (): void {
    // 1. Acquisto dal pannello: ordine in attesa, checkout aperto, redirect a Stripe.
    venuePanel();
    Livewire::test(VenueSponsorships::class)
        ->assertActionVisible('buy')
        ->callAction('buy', data: ['price' => $this->price->id, 'mode' => PromotionMode::Selected->value])
        ->assertHasNoActionErrors()
        ->assertRedirect('https://checkout.stripe.test/pay/'.$this->gateway->checkouts[0]['session']);
    $order = SponsorshipOrder::sole();
    expect($order->status)->toBe(SponsorshipOrderStatus::PendingPayment)
        ->and($order->venue_id)->toBe($this->venue->id)
        ->and($order->amount_cents)->toBe(16200)
        ->and($order->checkout_session_id)->toBe('cs_test_'.$order->id.'_1')
        ->and($this->gateway->checkouts[0]['success'])->toContain('order='.$order->id.'&session_id={CHECKOUT_SESSION_ID}')
        ->and($this->gateway->checkouts[0]['cancel'])->toContain('order='.$order->id.'&cancelled=1');

    // 2. Il webhook firmato segna il pagamento e avvisa la redazione; ripetuto, non cambia nulla.
    [$payload, $signature] = stripeSignedEvent($order->checkout_session_id, 'pi_test_123');
    postStripeWebhook($payload, $signature)->assertOk();
    $order->refresh();
    expect($order->status)->toBe(SponsorshipOrderStatus::Paid)->and($order->payment_intent_id)->toBe('pi_test_123')->and($order->paid_at)->not->toBeNull();
    Notification::assertSentTo($this->admin, SponsorshipOrderPaid::class);
    Notification::assertNotSentTo($this->owner, SponsorshipOrderPaid::class);
    postStripeWebhook($payload, $signature)->assertOk();
    Notification::assertSentToTimes($this->admin, SponsorshipOrderPaid::class, 1);
    expect(SponsorshipGrant::count())->toBe(0);

    // 3. La redazione approva: nasce l'abilitazione pagata, il periodo parte adesso.
    adminPanel();
    Livewire::test(ListOrders::class)->assertCanSeeTableRecords([$order])
        ->callTableAction('approve', $order)->assertHasNoTableActionErrors();
    $order->refresh();
    $grant = $order->grant;
    expect($order->status)->toBe(SponsorshipOrderStatus::Approved)
        ->and($order->decided_by)->toBe($this->admin->id)
        ->and($grant)->not->toBeNull()
        ->and($grant->venue_id)->toBe($this->venue->id)
        ->and($grant->mode)->toBe(PromotionMode::Selected)
        ->and($grant->placement)->toBe(SponsorshipPlacement::ListTop)
        ->and($grant->amount_cents)->toBe(16200)
        ->and($grant->payment_method)->toBe('stripe')
        ->and($grant->payment_reference)->toBe('pi_test_123')
        ->and($grant->ends_at->equalTo($grant->starts_at->setTimezone('Europe/Rome')->addMonthsNoOverflow(3)->utc()))->toBeTrue()
        ->and(SponsorshipGrant::active()->whereKey($grant->id)->exists())->toBeTrue();
    Notification::assertSentTo($this->owner, SponsorshipOrderDecided::class);

    // 4. Il locale sceglie l'evento: compare in cima a /eventi, dichiarato e col nome del locale.
    venuePanel();
    Livewire::test(VenueSponsorships::class)
        ->assertSee(__('promotions.orders.status.approved'))
        ->callAction('choose', data: ['grant' => $grant->id, 'event' => $this->event->id])->assertHasNoActionErrors();
    $campaign = Sponsorship::where('sponsorship_grant_id', $grant->id)->sole();
    expect(Sponsorship::visible()->whereKey($campaign->id)->exists())->toBeTrue()
        ->and($campaign->advertiser_name)->toBe('Circolo Aurora');
    $this->get('/eventi')->assertOk()
        ->assertSee(__('sponsorships.label'))
        ->assertSee('Circolo Aurora')
        ->assertSee('Concerto del circolo');
});

it('lets an organizer buy an automatic campaign that covers its events in any venue', function (): void {
    $organizerOwner = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->city->id, 'owner_id' => $organizerOwner->id, 'is_active' => true]);
    $elsewhere = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Teatro Belzoni']);
    $event = Event::factory()->create(['venue_id' => $elsewhere->id, 'organizer_id' => $organizer->id, 'city_id' => $this->city->id, 'category_id' => $this->category->id,
        'status' => EventStatus::Published, 'published_at' => now(), 'title' => 'Serata del collettivo']);
    EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHours(2)]);

    $this->actingAs($organizerOwner);
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organizer);
    Livewire::test(OrganizerSponsorships::class)
        ->callAction('buy', data: ['price' => $this->price->id, 'mode' => PromotionMode::Automatic->value])
        ->assertHasNoActionErrors()->assertRedirect('https://checkout.stripe.test/pay/'.$this->gateway->checkouts[0]['session']);
    $order = SponsorshipOrder::sole();
    expect($order->organizer_id)->toBe($organizer->id)->and($order->venue_id)->toBeNull();

    [$payload, $signature] = stripeSignedEvent($order->checkout_session_id, 'pi_org_1', 'checkout.session.async_payment_succeeded');
    postStripeWebhook($payload, $signature)->assertOk();
    expect($order->fresh()->status)->toBe(SponsorshipOrderStatus::Paid);

    adminPanel();
    Livewire::test(ListOrders::class)->callTableAction('approve', $order)->assertHasNoTableActionErrors();
    $grant = $order->fresh()->grant;
    expect($grant->organizer_id)->toBe($organizer->id)->and($grant->venue_id)->toBeNull()
        ->and($grant->sponsorships()->count())->toBe(1)
        ->and($grant->sponsorships()->first()->advertiser_name)->toBe('Collettivo Portici');

    // L'evento del circolo (altro committente) non e' coperto; quello del collettivo si'.
    expect(Sponsorship::visible()->where('event_id', $event->id)->exists())->toBeTrue()
        ->and(Sponsorship::visible()->where('event_id', $this->event->id)->exists())->toBeFalse();
    $this->get('/eventi')->assertOk()->assertSee(__('sponsorships.label'))->assertSee('Collettivo Portici')->assertSee('Serata del collettivo');

    // Un evento pubblicato dopo entra da solo nella campagna automatica.
    $later = Event::factory()->create(['venue_id' => $this->venue->id, 'organizer_id' => $organizer->id, 'city_id' => $this->city->id, 'category_id' => $this->category->id,
        'status' => EventStatus::Published, 'published_at' => now(), 'title' => 'Seconda serata']);
    app(GrantCampaigns::class)->sync($grant);
    expect($grant->sponsorships()->where('event_id', $later->id)->exists())->toBeTrue();
});

it('refunds and keeps the site clean when the newsroom rejects a paid order', function (): void {
    $order = app(SponsorshipOrders::class)->place($this->owner, $this->venue, $this->price, PromotionMode::Automatic, 'https://x.test/s?order={ORDER}', 'https://x.test/c?order={ORDER}');
    [$payload, $signature] = stripeSignedEvent($order->checkout_session_id, 'pi_refund_me');
    postStripeWebhook($payload, $signature)->assertOk();

    adminPanel();
    Livewire::test(ListOrders::class)->callTableAction('reject', $order->fresh(), ['reason' => 'Contenuto non adatto alla posizione richiesta'])->assertHasNoTableActionErrors();
    $order->refresh();
    expect($order->status)->toBe(SponsorshipOrderStatus::Rejected)
        ->and($order->decision_note)->toContain('Contenuto non adatto')->toContain(__('promotions.orders.refund_done'))
        ->and($this->gateway->refunds)->toBe(['pi_refund_me'])
        ->and(SponsorshipGrant::count())->toBe(0)
        ->and(Sponsorship::count())->toBe(0);
    Notification::assertSentTo($this->owner, SponsorshipOrderDecided::class);
    $this->get('/eventi')->assertOk()->assertDontSee(__('sponsorships.label'));

    // Il rifiuto regge anche se Stripe non rimborsa: la nota lo dice.
    $second = app(SponsorshipOrders::class)->place($this->owner, $this->venue, $this->price, PromotionMode::Selected, 'https://x.test/s', 'https://x.test/c');
    [$payload, $signature] = stripeSignedEvent($second->checkout_session_id, 'pi_stuck');
    postStripeWebhook($payload, $signature)->assertOk();
    $this->gateway->refundFailure = 'card_declined_refund';
    app(SponsorshipOrders::class)->reject($second->fresh(), $this->admin, 'Doppio ordine');
    expect($second->fresh()->status)->toBe(SponsorshipOrderStatus::Rejected)
        ->and($second->fresh()->decision_note)->toContain('card_declined_refund');
});

it('rejects unsigned or wrongly signed webhooks and ignores unknown sessions', function (): void {
    $order = app(SponsorshipOrders::class)->place($this->owner, $this->venue, $this->price, PromotionMode::Selected, 'https://x.test/s', 'https://x.test/c');
    [$payload] = stripeSignedEvent($order->checkout_session_id, 'pi_x');
    postStripeWebhook($payload, '')->assertStatus(400);
    [$payload, $wrong] = stripeSignedEvent($order->checkout_session_id, 'pi_x', secret: 'whsec_someone_else');
    postStripeWebhook($payload, $wrong)->assertStatus(400);
    expect($order->fresh()->status)->toBe(SponsorshipOrderStatus::PendingPayment);

    [$payload, $signature] = stripeSignedEvent('cs_unknown', 'pi_x');
    postStripeWebhook($payload, $signature)->assertOk();
    [$payload, $signature] = stripeSignedEvent($order->checkout_session_id, 'pi_x', 'customer.created');
    postStripeWebhook($payload, $signature)->assertOk();
    expect($order->fresh()->status)->toBe(SponsorshipOrderStatus::PendingPayment);

    config(['services.stripe.webhook_secret' => '']);
    [$payload, $signature] = stripeSignedEvent($order->checkout_session_id, 'pi_x');
    postStripeWebhook($payload, $signature)->assertStatus(400);
});

it('reconciles a paid session on the way back from checkout, only for the tenant that owns the order', function (): void {
    $order = app(SponsorshipOrders::class)->place($this->owner, $this->venue, $this->price, PromotionMode::Selected, 'https://x.test/s', 'https://x.test/c');
    $this->gateway->paidSessions[$order->checkout_session_id] = 'pi_from_return';

    // Un altro locale con lo stesso ordine nell'indirizzo non riconcilia niente.
    $otherVenue = Venue::factory()->approved()->create(['city_id' => $this->city->id]);
    $otherOwner = User::factory()->create();
    $otherOwner->assignRole(UserRole::VenueOwner->value);
    $otherOwner->venues()->attach($otherVenue, ['role' => 'owner']);
    $this->actingAs($otherOwner);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($otherVenue);
    Livewire::withQueryParams(['order' => $order->id, 'session_id' => $order->checkout_session_id])
        ->test(VenueSponsorships::class)->assertNotNotified(__('promotions.orders.returned_paid'))->assertDontSee('cs_test');
    expect($order->fresh()->status)->toBe(SponsorshipOrderStatus::PendingPayment);
    Livewire::test(VenueSponsorships::class)->call('cancelOrder', $order->id)->assertNotFound();

    venuePanel();
    Livewire::withQueryParams(['order' => $order->id, 'session_id' => $order->checkout_session_id])
        ->test(VenueSponsorships::class)->assertNotified(__('promotions.orders.returned_paid'));
    expect($order->fresh()->status)->toBe(SponsorshipOrderStatus::Paid)->and($order->fresh()->payment_intent_id)->toBe('pi_from_return');
    Notification::assertSentTo($this->admin, SponsorshipOrderPaid::class);

    // Tornare dopo aver chiuso Stripe lascia l'ordine riprovabile e annullabile.
    $pending = app(SponsorshipOrders::class)->place($this->owner, $this->venue, $this->price, PromotionMode::Selected, 'https://x.test/s', 'https://x.test/c');
    Livewire::withQueryParams(['order' => $pending->id, 'cancelled' => 1])
        ->test(VenueSponsorships::class)->assertNotified(__('promotions.orders.returned_cancelled'))
        ->call('retry', $pending->id)->assertRedirect('https://checkout.stripe.test/pay/cs_test_'.$pending->id.'_3');
    Livewire::test(VenueSponsorships::class)->call('cancelOrder', $pending->id)->assertNotified(__('promotions.orders.cancelled'));
    expect($pending->fresh()->status)->toBe(SponsorshipOrderStatus::Cancelled);
});

it('keeps buying out of reach without a price list or Stripe keys, and deciding out of reach for buyers', function (): void {
    venuePanel();
    $this->gateway->configured = false;
    Livewire::test(VenueSponsorships::class)->assertActionHidden('buy');
    $this->gateway->configured = true;
    $this->price->update(['is_active' => false]);
    Livewire::test(VenueSponsorships::class)->assertActionHidden('buy');
    expect(fn () => app(SponsorshipOrders::class)->place($this->owner, $this->venue, $this->price, PromotionMode::Selected, 's', 'c'))
        ->toThrow(HttpException::class);
    $this->price->update(['is_active' => true]);

    $order = app(SponsorshipOrders::class)->place($this->owner, $this->venue, $this->price, PromotionMode::Selected, 's', 'c');
    app(SponsorshipOrders::class)->markPaid($order, 'pi_1', now()->toImmutable());
    expect($this->owner->can('decide', $order->fresh()))->toBeFalse()
        ->and($this->owner->can('viewAny', SponsorshipOrder::class))->toBeFalse()
        ->and($this->admin->can('decide', $order->fresh()))->toBeTrue();
    $this->get('/admin/sponsorship-orders')->assertForbidden();

    // Un collaboratore del locale cura gli eventi ma non firma acquisti.
    $editor = User::factory()->create();
    $editor->assignRole(UserRole::VenueEditor->value);
    $editor->venues()->attach($this->venue, ['role' => 'editor']);
    $this->actingAs($editor);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
    Livewire::test(VenueSponsorships::class)->assertActionHidden('buy')
        ->assertDontSee(__('promotions.orders.cancel'))
        ->call('cancelOrder', $order->id)->assertForbidden();
    expect(fn () => app(SponsorshipOrders::class)->place($editor, $this->venue, $this->price, PromotionMode::Selected, 's', 'c'))
        ->toThrow(AuthorizationException::class);
    expect(SponsorshipOrder::count())->toBe(1);

    // Un locale non approvato non compra.
    $this->venue->update(['status' => 'suspended']);
    expect(fn () => app(SponsorshipOrders::class)->place($this->owner, $this->venue->fresh(), $this->price, PromotionMode::Selected, 's', 'c'))
        ->toThrow(HttpException::class);
});
