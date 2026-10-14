<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Payment;

/**
 * Quello che del checkout serve a noi: dove mandare la persona, e poi se ha
 * pagato e con quale incasso.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $id,
        public ?string $url = null,
        public bool $paid = false,
        public ?string $paymentIntentId = null,
    ) {}
}
