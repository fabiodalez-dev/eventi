<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Payment;

use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Verifica che un webhook venga davvero da Stripe.
 *
 * Non passa dall'interfaccia del gateway di proposito: non parla con nessuno,
 * e cosi' i test firmano un payload con lo stesso segreto e attraversano il
 * controllo vero invece di un finto che dice sempre di si'.
 */
final class StripeWebhookSignature
{
    public const HEADER = 'Stripe-Signature';

    /**
     * @throws PaymentGatewayException quando la firma non torna o il corpo non e' un evento
     */
    public function event(string $payload, string $signature): Event
    {
        $secret = trim((string) config('services.stripe.webhook_secret'));

        if ($secret === '') {
            throw new PaymentGatewayException('Stripe webhook secret is not configured.');
        }

        try {
            return Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new PaymentGatewayException($e->getMessage(), previous: $e);
        }
    }
}
