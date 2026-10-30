<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Payment;

use App\Models\SponsorshipOrder;

/**
 * Il fornitore del pagamento, visto dal servizio degli ordini.
 *
 * Quattro gesti soltanto: aprire una pagina di pagamento, rileggerla,
 * chiuderla, rimborsare.
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
     * Chiude una pagina di pagamento ancora aperta, cosi' che non si possa
     * piu' pagare. Su una sessione gia' scaduta o completata non fa niente:
     * se era pagata lo dice `session()`, che chi chiama legge prima.
     *
     * @throws PaymentGatewayException
     */
    public function expire(string $sessionId): void;

    /**
     * @throws PaymentGatewayException
     */
    public function refund(string $paymentIntentId): void;
}
