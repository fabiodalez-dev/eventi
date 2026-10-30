<?php

declare(strict_types=1);

use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\UserRole;
use App\Filament\Venue\Pages\Sponsorships as VenueSponsorships;
use App\Models\SponsorshipPrice;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\SponsorshipOrderDecided;
use App\Notifications\SponsorshipOrderPaid;
use App\Notifications\SponsorshipStrayRefundFailed;
use App\Services\Sponsorship\OrderNoLongerPayable;
use App\Services\Sponsorship\Payment\PaymentGateway;
use App\Services\Sponsorship\SponsorshipOrders;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FakePaymentGateway;

/*
 * Una sola pagina di pagamento aperta per ordine, e nessun incasso tenuto in
 * silenzio: riprovare e annullare chiudono la sessione precedente, il webhook
 * ritrova l'ordine anche da una sessione superata, e un pagamento che l'ordine
 * non aspettava piu' si rimborsa. Riprovare non paga una tariffa ritirata.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
    config(['services.stripe.secret' => 'sk_test_fake', 'services.stripe.webhook_secret' => 'whsec_test_fake']);
    $this->gateway = new FakePaymentGateway;
    app()->instance(PaymentGateway::class, $this->gateway);

    $this->venue = Venue::factory()->approved()->create(['city_id' => testCity()->id]);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(UserRole::VenueOwner->value);
    $this->owner->venues()->attach($this->venue, ['role' => 'owner']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);
    $this->price = SponsorshipPrice::create(['placement' => 'list_top', 'months' => 3, 'amount_cents' => 16200, 'currency' => 'EUR', 'is_active' => true]);
    $this->order = app(SponsorshipOrders::class)->place($this->owner, $this->venue, $this->price, PromotionMode::Selected, 'https://x.test/s?order={ORDER}', 'https://x.test/c?order={ORDER}');
    $this->firstSession = $this->order->checkout_session_id;
});

/**
 * Un evento firmato come lo firma Stripe, con i metadati che il gateway mette
 * sulla sessione.
 *
 * @param  array<string, mixed>  $extra
 */
function paymentsSignedEvent(string $sessionId, string $intent, array $extra = []): array
{
    $payload = json_encode(['id' => 'evt_'.$sessionId.'_'.$intent, 'object' => 'event', 'type' => 'checkout.session.completed', 'created' => time(), 'api_version' => '2024-06-20',
        'data' => ['object' => array_merge(['id' => $sessionId, 'object' => 'checkout.session', 'payment_status' => 'paid', 'payment_intent' => $intent], $extra)]], JSON_THROW_ON_ERROR);
    $timestamp = time();

    return [$payload, 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test_fake')];
}

function paymentsPostWebhook(string $payload, string $signature)
{
    return test()->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload);
}

function paymentsVenuePanel(): void
{
    test()->actingAs(test()->owner)->get('/gestione/'.test()->venue->getRouteKey().'/sponsorizzazioni')->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant(test()->venue);
}

it('riprovare il pagamento fa scadere la sessione precedente prima di aprirne una nuova', function (): void {
    paymentsVenuePanel();
    Livewire::test(VenueSponsorships::class)->call('retry', $this->order->id)
        ->assertRedirect('https://checkout.stripe.test/pay/cs_test_'.$this->order->id.'_2');

    expect($this->gateway->expired)->toBe([$this->firstSession])
        ->and($this->order->fresh()->checkout_session_id)->toBe('cs_test_'.$this->order->id.'_2');
});

it('il webhook di una sessione superata ritrova l’ordine dai metadati e lo segna pagato', function (): void {
    app(SponsorshipOrders::class)->openCheckout($this->order, 'https://x.test/s', 'https://x.test/c');
    expect($this->order->fresh()->checkout_session_id)->not->toBe($this->firstSession);

    [$payload, $signature] = paymentsSignedEvent($this->firstSession, 'pi_old_tab', [
        'client_reference_id' => (string) $this->order->id, 'amount_total' => 16200, 'currency' => 'eur',
        'metadata' => ['sponsorship_order_id' => (string) $this->order->id],
    ]);
    paymentsPostWebhook($payload, $signature)->assertOk();

    $order = $this->order->fresh();
    expect($order->status)->toBe(SponsorshipOrderStatus::Paid)->and($order->payment_intent_id)->toBe('pi_old_tab');
    Notification::assertSentTo($this->admin, SponsorshipOrderPaid::class);
});

it('non risolve dai metadati una sessione con importo o valuta diversi dall’ordine', function (): void {
    app(SponsorshipOrders::class)->openCheckout($this->order, 'https://x.test/s', 'https://x.test/c');

    [$payload, $signature] = paymentsSignedEvent($this->firstSession, 'pi_wrong', [
        'amount_total' => 100, 'currency' => 'eur', 'metadata' => ['sponsorship_order_id' => (string) $this->order->id],
    ]);
    paymentsPostWebhook($payload, $signature)->assertOk();
    [$payload, $signature] = paymentsSignedEvent($this->firstSession, 'pi_wrong_currency', [
        'amount_total' => 16200, 'currency' => 'usd', 'metadata' => ['sponsorship_order_id' => (string) $this->order->id],
    ]);
    paymentsPostWebhook($payload, $signature)->assertOk();

    expect($this->order->fresh()->status)->toBe(SponsorshipOrderStatus::PendingPayment)
        ->and($this->gateway->refunds)->toBe([]);
});

it('annullare un ordine chiude la sua pagina di pagamento', function (): void {
    paymentsVenuePanel();
    Livewire::test(VenueSponsorships::class)->call('cancelOrder', $this->order->id)->assertNotified(__('promotions.orders.cancelled'));

    expect($this->order->fresh()->status)->toBe(SponsorshipOrderStatus::Cancelled)
        ->and($this->gateway->expired)->toBe([$this->firstSession]);
});

it('un pagamento arrivato su un ordine annullato viene rimborsato e annotato', function (): void {
    app(SponsorshipOrders::class)->cancel($this->order);

    [$payload, $signature] = paymentsSignedEvent($this->firstSession, 'pi_late');
    paymentsPostWebhook($payload, $signature)->assertOk();
    paymentsPostWebhook($payload, $signature)->assertOk();

    $order = $this->order->fresh();
    expect($order->status)->toBe(SponsorshipOrderStatus::Cancelled)
        ->and($order->payment_intent_id)->toBeNull()
        ->and($this->gateway->refunds)->toBe(['pi_late'])
        ->and($order->decision_note)->toContain('pi_late')->toContain(__('promotions.orders.stray_refund_done', ['intent' => 'pi_late']));
    Notification::assertNothingSentTo($this->admin);
});

it('un secondo incasso sullo stesso ordine pagato viene rimborsato, il primo resta', function (): void {
    app(SponsorshipOrders::class)->markPaid($this->order, 'pi_first', now()->toImmutable());
    app(SponsorshipOrders::class)->markPaid($this->order, 'pi_first', now()->toImmutable());
    expect($this->gateway->refunds)->toBe([]);

    app(SponsorshipOrders::class)->markPaid($this->order, 'pi_second', now()->toImmutable());

    $order = $this->order->fresh();
    expect($order->status)->toBe(SponsorshipOrderStatus::Paid)
        ->and($order->payment_intent_id)->toBe('pi_first')
        ->and($this->gateway->refunds)->toBe(['pi_second']);
    Notification::assertSentToTimes($this->admin, SponsorshipOrderPaid::class, 1);
});

it('annullare un ordine la cui sessione risulta già pagata lo segna pagato invece di annullarlo', function (): void {
    $this->gateway->paidSessions[$this->firstSession] = 'pi_paid_meanwhile';

    paymentsVenuePanel();
    Livewire::test(VenueSponsorships::class)->call('cancelOrder', $this->order->id)->assertNotified(__('promotions.orders.returned_paid'));

    $order = $this->order->fresh();
    expect($order->status)->toBe(SponsorshipOrderStatus::Paid)
        ->and($order->payment_intent_id)->toBe('pi_paid_meanwhile')
        ->and($this->gateway->expired)->toBe([]);
});

it('riprovare su una sessione già pagata non apre un secondo checkout', function (): void {
    $this->gateway->paidSessions[$this->firstSession] = 'pi_paid_meanwhile';

    paymentsVenuePanel();
    Livewire::test(VenueSponsorships::class)->call('retry', $this->order->id)
        ->assertNoRedirect()->assertNotified(__('promotions.orders.returned_paid'));

    expect($this->order->fresh()->status)->toBe(SponsorshipOrderStatus::Paid)
        ->and($this->gateway->checkouts)->toHaveCount(1);
});

it('riprovare non paga un ordine se la tariffa è stata ritirata o cambiata, o se il locale non è più operativo', function (callable $change): void {
    $change($this);

    paymentsVenuePanel();
    Livewire::test(VenueSponsorships::class)->call('retry', $this->order->id)
        ->assertNoRedirect()->assertNotified(__('promotions.orders.no_longer_payable'));

    expect($this->order->fresh()->status)->toBe(SponsorshipOrderStatus::Cancelled)
        ->and($this->gateway->checkouts)->toHaveCount(1)
        ->and($this->gateway->expired)->toBe([$this->firstSession]);
})->with([
    'tariffa disattivata' => [fn ($test) => $test->price->update(['is_active' => false])],
    'importo cambiato' => [fn ($test) => $test->price->update(['amount_cents' => 30000])],
    'durata cambiata' => [fn ($test) => $test->price->update(['months' => 6])],
    'tariffa cancellata' => [fn ($test) => $test->price->delete()],
]);

it('il servizio rifiuta di riaprire il checkout per un committente sospeso', function (): void {
    $this->venue->update(['status' => 'suspended']);

    expect(fn () => app(SponsorshipOrders::class)->openCheckout($this->order->fresh(), 'https://x.test/s', 'https://x.test/c'))
        ->toThrow(OrderNoLongerPayable::class);
    expect($this->order->fresh()->status)->toBe(SponsorshipOrderStatus::Cancelled)
        ->and($this->gateway->checkouts)->toHaveCount(1);
});

it('un ordine la cui sessione l’account Stripe non conosce più si annulla lo stesso', function (): void {
    /* Ordine nato con le chiavi di test, poi si passa a quelle live: Stripe
       risponde «No such checkout.session» e l'ordine non deve restare
       bloccato in attesa per sempre. */
    $this->gateway->missingSessions[] = $this->firstSession;

    paymentsVenuePanel();
    Livewire::test(VenueSponsorships::class)->call('cancelOrder', $this->order->id)->assertNotified(__('promotions.orders.cancelled'));

    expect($this->order->fresh()->status)->toBe(SponsorshipOrderStatus::Cancelled)
        ->and($this->gateway->expired)->toBe([]);
});

it('riprovare un ordine la cui sessione l’account Stripe non conosce più apre una pagina nuova', function (): void {
    $this->gateway->missingSessions[] = $this->firstSession;

    paymentsVenuePanel();
    Livewire::test(VenueSponsorships::class)->call('retry', $this->order->id)
        ->assertRedirect('https://checkout.stripe.test/pay/cs_test_'.$this->order->id.'_2');

    expect($this->order->fresh()->checkout_session_id)->toBe('cs_test_'.$this->order->id.'_2')
        ->and($this->order->fresh()->status)->toBe(SponsorshipOrderStatus::PendingPayment);
});

it('un guasto passeggero di Stripe non annulla un ordine che forse è pagato', function (): void {
    $this->gateway->sessionFailure = 'Could not connect to Stripe';

    paymentsVenuePanel();
    Livewire::test(VenueSponsorships::class)->call('cancelOrder', $this->order->id)->assertNotified(__('promotions.orders.cancel_failed'));

    expect($this->order->fresh()->status)->toBe(SponsorshipOrderStatus::PendingPayment);
});

it('se il rimborso di un incasso inatteso fallisce la redazione riceve un’email', function (): void {
    app(SponsorshipOrders::class)->markPaid($this->order, 'pi_first', now()->toImmutable());
    $this->gateway->refundFailure = 'Stripe down';

    app(SponsorshipOrders::class)->markPaid($this->order, 'pi_second', now()->toImmutable());

    Notification::assertSentTo($this->admin, SponsorshipStrayRefundFailed::class);
    Notification::assertNotSentTo($this->owner, SponsorshipStrayRefundFailed::class);
    expect($this->order->fresh()->decision_note)->toContain(__('promotions.orders.stray_refund_failed', ['intent' => 'pi_second', 'error' => 'Stripe down']));
});

it('un incasso inatteso rimborsato da solo non manda email alla redazione', function (): void {
    app(SponsorshipOrders::class)->markPaid($this->order, 'pi_first', now()->toImmutable());
    app(SponsorshipOrders::class)->markPaid($this->order, 'pi_second', now()->toImmutable());

    Notification::assertNotSentTo($this->admin, SponsorshipStrayRefundFailed::class);
});

it('rifiutare un ordine non cancella la nota di un rimborso da fare a mano', function (): void {
    $orders = app(SponsorshipOrders::class);
    $orders->markPaid($this->order, 'pi_first', now()->toImmutable());
    $this->gateway->refundFailure = 'Stripe down';
    $orders->markPaid($this->order, 'pi_second', now()->toImmutable());
    $this->gateway->refundFailure = null;

    $orders->reject($this->order->fresh(), $this->admin, 'Contenuto non adatto');

    $note = (string) $this->order->fresh()->decision_note;
    /* Il motivo resta in prima riga, perche' e' quella che vede chi ha
       pagato; la nota sull'incasso inatteso resta sotto. */
    expect(Str::before($note, "\n"))->toBe('Contenuto non adatto')
        ->and($note)->toContain(__('promotions.orders.refund_done'))
        ->and($note)->toContain(__('promotions.orders.stray_refund_failed', ['intent' => 'pi_second', 'error' => 'Stripe down']))
        ->and($this->gateway->refunds)->toBe(['pi_first']);

    /* A chi ha pagato arriva solo il motivo, non le note interne sul rimborso. */
    Notification::assertSentTo($this->order->fresh()->user, SponsorshipOrderDecided::class, function (SponsorshipOrderDecided $n, array $c, object $buyer): bool {
        $mail = implode("\n", $n->toMail($buyer)->introLines);

        return str_contains($mail, 'Contenuto non adatto') && ! str_contains($mail, 'pi_second') && ! str_contains($mail, 'Stripe down');
    });

    /* E la deduplica regge: lo stesso incasso che torna non riparte. */
    $orders->markPaid($this->order, 'pi_second', now()->toImmutable());
    expect($this->gateway->refunds)->toBe(['pi_first']);
});
