<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use Carbon\CarbonImmutable;

/** Il primo rilascio: i pesi degli eventi restano quelli della vicinanza della data. */
final class NeutralEventWeightAdjuster implements EventWeightAdjuster
{
    public function adjust(string $unitKey, array $weights, CarbonImmutable $now): array
    {
        return $weights;
    }
}
