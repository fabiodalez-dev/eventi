<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Payment;

use App\Models\SponsorshipOrder;

/**
 * Il fornitore del pagamento, visto dal servizio degli ordini.
 *
 * Tre gesti soltanto: aprire una pagina di pagamento, rileggerla, rimborsare.
 * Il webhook non sta qui perche' non parla con nessuno: verifica una firma
 * in locale, e i test lo esercitano davvero (`StripeWebhookSignature`).
 */
interface PaymentGateway
{
    public function isConfigured(): bool;

    /**
     * @throws PaymentGatewayException
     */
    public function checkout(SponsorshipOrder $order, string $successUrl, string $cancelUrl): CheckoutSession;

    /**
     * @throws PaymentGatewayException
     */
    public function session(string $sessionId): CheckoutSession;

    /**
     * @throws PaymentGatewayException
     */
    public function refund(string $paymentIntentId): void;
}
