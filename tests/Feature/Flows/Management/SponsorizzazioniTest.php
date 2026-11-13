<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
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
use App\Services\Sponsorship\Payment\PaymentGateway;
use App\Services\Sponsorship\SponsorshipOrders;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\FakePaymentGateway;

/**
 * Flusso: una sponsorizzazione comprata da chi gestisce un locale (o da
 * un'organizzazione), pagata su un gateway finto, confermata dal webhook di
 * Stripe firmato e decisa dalla redazione. Alla fine la campagna compare nel
 * banner e in cima a `/eventi` — oppure, se la redazione rifiuta, viene
 * rimborsata e non compare da nessuna parte.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
    config(['services.stripe.secret' => 'sk_test_flussi', 'services.stripe.webhook_secret' => 'whsec_test_flussi']);
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

/** @return array{0: string, 1: string} corpo e intestazione firmata come la firma Stripe */
function gstEventoStripe(string $sessionId, string $intent, string $type = 'checkout.session.completed', string $secret = 'whsec_test_flussi'): array
{
    $payload = json_encode(['id' => 'evt_'.$sessionId, 'object' => 'event', 'type' => $type, 'created' => time(), 'api_version' => '2024-06-20',
        'data' => ['object' => ['id' => $sessionId, 'object' => 'checkout.session', 'payment_status' => 'paid', 'payment_intent' => $intent]]], JSON_THROW_ON_ERROR);
    $timestamp = time();

    return [$payload, 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret)];
}

function gstWebhookStripe(string $payload, ?string $signature)
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($signature !== null) {
        $server['HTTP_STRIPE_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/webhooks/stripe', [], [], [], $server, $payload);
}

it('porta un locale dall\'acquisto al banner: firma falsa respinta, firma vera accolta, redazione che approva', function (): void {
    // Prima di tutto, nessun banner sponsorizzato.
    $this->getJson('/api/v1/sponsorships/banner?platform=web')->assertOk()->assertJsonPath('data', null);

    // 1. Il gestore apre la pagina delle sponsorizzazioni e compra.
    $this->actingAs($this->owner)->get('/gestione/'.$this->venue->slug.'/sponsorizzazioni')->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
    Livewire::test(VenueSponsorships::class)
        ->assertActionVisible('buy')
        ->callAction('buy', data: ['price' => $this->price->id, 'mode' => PromotionMode::Selected->value])
        ->assertHasNoActionErrors()
        ->assertRedirect('https://checkout.stripe.test/pay/'.$this->gateway->checkouts[0]['session']);

    $order = SponsorshipOrder::query()->sole();
    expect($order->status)->toBe(SponsorshipOrderStatus::PendingPayment)
        ->and($order->venue_id)->toBe($this->venue->id)
        ->and($order->amount_cents)->toBe(16200);

    // 2. Un webhook senza firma o con la firma di un altro segreto non conta.
    [$payload] = gstEventoStripe($order->checkout_session_id, 'pi_flusso_1');
    gstWebhookStripe($payload, null)->assertStatus(400);
    [$payload, $falsa] = gstEventoStripe($order->checkout_session_id, 'pi_flusso_1', secret: 'whsec_di_qualcun_altro');
    gstWebhookStripe($payload, $falsa)->assertStatus(400);
    expect($order->fresh()->status)->toBe(SponsorshipOrderStatus::PendingPayment);
    Notification::assertNothingSent();

    // 3. Quello firmato davvero segna il pagamento e avvisa la redazione.
    [$payload, $firma] = gstEventoStripe($order->checkout_session_id, 'pi_flusso_1');
    gstWebhookStripe($payload, $firma)->assertOk();
    $order->refresh();
    expect($order->status)->toBe(SponsorshipOrderStatus::Paid)
        ->and($order->payment_intent_id)->toBe('pi_flusso_1');
    Notification::assertSentTo($this->admin, SponsorshipOrderPaid::class);

    // Pagato non vuol dire pubblicato: il banner resta vuoto.
    $this->getJson('/api/v1/sponsorships/banner?platform=web')->assertJsonPath('data', null);

    // 4. La redazione approva dall'elenco ordini.
    $this->actingAs($this->admin)->get('/admin/sponsorship-orders')->assertOk();
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    Livewire::test(ListOrders::class)->assertCanSeeTableRecords([$order])
        ->callTableAction('approve', $order)->assertHasNoTableActionErrors();
    $grant = $order->fresh()->grant;
    expect($order->fresh()->status)->toBe(SponsorshipOrderStatus::Approved)
        ->and($grant)->not->toBeNull()
        ->and($grant->payment_reference)->toBe('pi_flusso_1')
        ->and(SponsorshipGrant::active()->whereKey($grant->id)->exists())->toBeTrue();
    Notification::assertSentTo($this->owner, SponsorshipOrderDecided::class);

    // 5. Il gestore sceglie l'evento: la campagna è attiva e compare nel banner.
    $this->actingAs($this->owner);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
    Livewire::test(VenueSponsorships::class)
        ->callAction('choose', data: ['grant' => $grant->id, 'event' => $this->event->id])
        ->assertHasNoActionErrors();

    $campaign = Sponsorship::query()->where('sponsorship_grant_id', $grant->id)->sole();
    expect(Sponsorship::visible()->whereKey($campaign->id)->exists())->toBeTrue();

    auth()->logout();
    $this->getJson('/api/v1/sponsorships/banner?platform=web')->assertOk()
        ->assertJsonPath('data.id', $campaign->id)
        ->assertJsonPath('data.title', 'Concerto del circolo')
        ->assertJsonPath('data.advertiser', 'Circolo Aurora')
        ->assertJsonPath('data.event_slug', $this->event->slug);
    $this->get('/eventi')->assertOk()
        ->assertSee(__('sponsorships.label'))
        ->assertSee('Concerto del circolo');
});

it('rimborsa l\'ordine di un\'organizzazione rifiutato dalla redazione e tiene acquisti e decisioni fuori dalla portata dei ruoli sbagliati', function (): void {
    $organizerOwner = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->city->id, 'owner_id' => $organizerOwner->id, 'is_active' => true]);
    $orgEvent = Event::factory()->create(['venue_id' => $this->venue->id, 'organizer_id' => $organizer->id, 'city_id' => $this->city->id,
        'category_id' => $this->category->id, 'status' => EventStatus::Published, 'published_at' => now(), 'title' => 'Serata del collettivo']);
    EventOccurrence::factory()->create(['event_id' => $orgEvent->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHours(2)]);

    // 1. L'organizzazione compra una campagna automatica.
    $this->actingAs($organizerOwner)->get('/organizza/'.$organizer->slug.'/sponsorizzazioni')->assertOk();
    Filament::setCurrentPanel('organizer');
    Filament::setTenant($organizer);
    Livewire::test(OrganizerSponsorships::class)
        ->callAction('buy', data: ['price' => $this->price->id, 'mode' => PromotionMode::Automatic->value])
        ->assertHasNoActionErrors();
    $order = SponsorshipOrder::query()->sole();
    expect($order->organizer_id)->toBe($organizer->id)->and($order->venue_id)->toBeNull();

    // 2. Il pagamento asincrono arriva firmato.
    [$payload, $firma] = gstEventoStripe($order->checkout_session_id, 'pi_da_rimborsare', 'checkout.session.async_payment_succeeded');
    gstWebhookStripe($payload, $firma)->assertOk();
    expect($order->fresh()->status)->toBe(SponsorshipOrderStatus::Paid);

    // 3. Chi compra non decide: né l'organizzazione né il gestore del locale.
    $this->get('/admin/sponsorship-orders')->assertForbidden();
    expect($organizerOwner->can('decide', $order->fresh()))->toBeFalse()
        ->and($this->owner->can('decide', $order->fresh()))->toBeFalse();

    // Un collaboratore del locale non firma acquisti.
    $editor = User::factory()->create();
    $editor->assignRole(UserRole::VenueEditor->value);
    $editor->venues()->attach($this->venue, ['role' => 'editor']);
    $this->actingAs($editor)->get('/gestione/'.$this->venue->slug.'/sponsorizzazioni')->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
    Livewire::test(VenueSponsorships::class)->assertActionHidden('buy');
    expect(fn () => app(SponsorshipOrders::class)->place($editor, $this->venue, $this->price, PromotionMode::Selected, 's', 'c'))
        ->toThrow(AuthorizationException::class);

    // 4. La redazione rifiuta: rimborso chiesto al gateway, nessuna campagna.
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
    Livewire::test(ListOrders::class)
        ->callTableAction('reject', $order->fresh(), ['reason' => 'Contenuto non adatto alla posizione richiesta'])
        ->assertHasNoTableActionErrors();

    $order->refresh();
    expect($order->status)->toBe(SponsorshipOrderStatus::Rejected)
        ->and($order->decision_note)->toContain('Contenuto non adatto')
        ->and($this->gateway->refunds)->toBe(['pi_da_rimborsare'])
        ->and(SponsorshipOrder::query()->count())->toBe(1)
        ->and(SponsorshipGrant::query()->count())->toBe(0)
        ->and(Sponsorship::query()->count())->toBe(0);
    Notification::assertSentTo($organizerOwner, SponsorshipOrderDecided::class);

    auth()->logout();
    $this->getJson('/api/v1/sponsorships/banner?platform=web')->assertJsonPath('data', null);
    $this->get('/eventi')->assertOk()->assertDontSee(__('sponsorships.label'));
});
