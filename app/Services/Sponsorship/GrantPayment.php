<?php

declare(strict_types=1);

namespace App\Services\Sponsorship;

final class GrantPayment
{
    public const FIELDS = ['amount_cents', 'paid_at', 'payment_method', 'payment_reference'];

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalize(array $data): array
    {
        // Hidden fields are omitted by Filament. Explicit nulls also clear old payment
        // values on edit; the existing activity log retains their previous values.
        return ($data['complimentary'] ?? false)
            ? array_replace($data, array_fill_keys(self::FIELDS, null))
            : $data;
    }
}
