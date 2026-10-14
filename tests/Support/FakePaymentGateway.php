<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\SponsorshipOrder;
use App\Services\Sponsorship\Payment\CheckoutSession;
use App\Services\Sponsorship\Payment\PaymentGateway;
use App\Services\Sponsorship\Payment\PaymentGatewayException;

/**
 * Stripe senza Stripe: registra le sessioni aperte e i rimborsi chiesti, e
 * dice «pagato» per le sessioni che il test ha marcato tali.
 */
final class FakePaymentGateway implements PaymentGateway
{
    public bool $configured = true;

    public ?string $refundFailure = null;

    /** @var list<array{order: int, success: string, cancel: string, session: string}> */
    public array $checkouts = [];

    /** @var array<string, string> session id => payment intent */
    public array $paidSessions = [];

    /** @var list<string> */
    public array $refunds = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function checkout(SponsorshipOrder $order, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $id = sprintf('cs_test_%d_%d', $order->getKey(), count($this->checkouts) + 1);
        $this->checkouts[] = ['order' => (int) $order->getKey(), 'success' => $successUrl, 'cancel' => $cancelUrl, 'session' => $id];

        return new CheckoutSession($id, 'https://checkout.stripe.test/pay/'.$id);
    }

    public function session(string $sessionId): CheckoutSession
    {
        $intent = $this->paidSessions[$sessionId] ?? null;

        return new CheckoutSession($sessionId, null, $intent !== null, $intent);
    }

    public function refund(string $paymentIntentId): void
    {
        if ($this->refundFailure !== null) {
            throw new PaymentGatewayException($this->refundFailure);
        }

        $this->refunds[] = $paymentIntentId;
    }
}
