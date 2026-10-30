<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Payment;

use App\Models\SponsorshipOrder;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Throwable;

/**
 * Stripe Checkout: la pagina di pagamento la ospita Stripe, il sito non vede
 * mai la carta.
 *
 * Il cliente si costruisce alla prima chiamata e non nel costruttore: il
 * servizio degli ordini viene istanziato anche su pagine che non pagano
 * niente, e senza chiave non deve fallire prima ancora di servire.
 */
final class StripeGateway implements PaymentGateway
{
    private ?StripeClient $client = null;

    public function isConfigured(): bool
    {
        return trim((string) config('services.stripe.secret')) !== '';
    }

    public function checkout(SponsorshipOrder $order, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $sponsor = $order->sponsor();

        try {
            $session = $this->client()->checkout->sessions->create([
                'mode' => 'payment',
                'client_reference_id' => (string) $order->getKey(),
                'customer_email' => $order->user?->email,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($order->currency),
                        'unit_amount' => $order->amount_cents,
                        'product_data' => [
                            'name' => __('promotions.orders.checkout_item', [
                                'placement' => $order->placement->label(),
                                'months' => $order->months,
                                'sponsor' => $sponsor->name,
                            ]),
                        ],
                    ],
                ]],
                'metadata' => [
                    'sponsorship_order_id' => (string) $order->getKey(),
                    'sponsor' => $sponsor::class.':'.$sponsor->getKey(),
                ],
            ]);
        } catch (ApiErrorException $e) {
            throw new PaymentGatewayException($e->getMessage(), previous: $e);
        }

        return $this->fromSession($session);
    }

    public function session(string $sessionId): CheckoutSession
    {
        try {
            return $this->fromSession($this->client()->checkout->sessions->retrieve($sessionId));
        } catch (InvalidRequestException $e) {
            throw $this->missingOr($e);
        } catch (ApiErrorException $e) {
            throw new PaymentGatewayException($e->getMessage(), previous: $e);
        }
    }

    public function expire(string $sessionId): void
    {
        try {
            /* Stripe rifiuta di far scadere una sessione che non e' piu'
               aperta: quella gia' scaduta va bene cosi', quella completata
               l'ha gia' riletta chi chiama. */
            if ($this->client()->checkout->sessions->retrieve($sessionId)->status === Session::STATUS_OPEN) {
                $this->client()->checkout->sessions->expire($sessionId);
            }
        } catch (InvalidRequestException $e) {
            throw $this->missingOr($e);
        } catch (ApiErrorException $e) {
            throw new PaymentGatewayException($e->getMessage(), previous: $e);
        }
    }

    public function refund(string $paymentIntentId): void
    {
        try {
            $this->client()->refunds->create(['payment_intent' => $paymentIntentId]);
        } catch (ApiErrorException $e) {
            throw new PaymentGatewayException($e->getMessage(), previous: $e);
        }
    }

    /**
     * «No such checkout.session» non passa da solo: la sessione e' di un
     * altro account (o delle chiavi di test), e chi chiama deve poterla
     * distinguere da un errore di rete. Gli altri rifiuti restano generici.
     */
    private function missingOr(InvalidRequestException $e): PaymentGatewayException
    {
        return $e->getStripeCode() === 'resource_missing'
            ? new PaymentSessionMissing($e->getMessage(), previous: $e)
            : new PaymentGatewayException($e->getMessage(), previous: $e);
    }

    private function fromSession(Session $session): CheckoutSession
    {
        $intent = $session->payment_intent;

        return new CheckoutSession(
            id: (string) $session->id,
            url: is_string($session->url) ? $session->url : null,
            paid: $session->payment_status === 'paid',
            paymentIntentId: is_string($intent) ? $intent : ($intent->id ?? null),
        );
    }

    private function client(): StripeClient
    {
        if (! $this->isConfigured()) {
            throw new PaymentGatewayException('Stripe is not configured: set STRIPE_SECRET.');
        }

        try {
            return $this->client ??= new StripeClient((string) config('services.stripe.secret'));
        } catch (Throwable $e) {
            throw new PaymentGatewayException($e->getMessage(), previous: $e);
        }
    }
}
