<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\SponsorshipOrder;
use App\Services\Sponsorship\Payment\CheckoutSession;
use App\Services\Sponsorship\Payment\PaymentGateway;
use App\Services\Sponsorship\Payment\PaymentGatewayException;
use App\Services\Sponsorship\Payment\PaymentSessionMissing;

/**
 * Stripe senza Stripe: registra le sessioni aperte, quelle chiuse e i
 * rimborsi chiesti, e dice «pagato» per le sessioni che il test ha marcato
 * tali.
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

    /** @var list<string> sessioni fatte scadere quando erano ancora aperte */
    public array $expired = [];

    public ?string $expireFailure = null;

    /** @var list<string> sessioni che l'account non conosce (chiavi o account cambiati) */
    public array $missingSessions = [];

    public ?string $sessionFailure = null;

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
        if (in_array($sessionId, $this->missingSessions, true)) {
            throw new PaymentSessionMissing('No such checkout.session: '.$sessionId);
        }

        if ($this->sessionFailure !== null) {
            throw new PaymentGatewayException($this->sessionFailure);
        }

        $intent = $this->paidSessions[$sessionId] ?? null;

        return new CheckoutSession($sessionId, null, $intent !== null, $intent);
    }

    public function expire(string $sessionId): void
    {
        if (in_array($sessionId, $this->missingSessions, true)) {
            throw new PaymentSessionMissing('No such checkout.session: '.$sessionId);
        }

        if ($this->expireFailure !== null) {
            throw new PaymentGatewayException($this->expireFailure);
        }

        /* Come Stripe: una sessione pagata non si chiude piu'. */
        if (! isset($this->paidSessions[$sessionId]) && ! in_array($sessionId, $this->expired, true)) {
            $this->expired[] = $sessionId;
        }
    }

    public function refund(string $paymentIntentId): void
    {
        if ($this->refundFailure !== null) {
            throw new PaymentGatewayException($this->refundFailure);
        }

        $this->refunds[] = $paymentIntentId;
    }
}
