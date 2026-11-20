<?php

declare(strict_types=1);

namespace App\Services\Sponsorship\Delivery;

use App\Models\User;
use Carbon\CarbonImmutable;

/** Il primo rilascio: nessun tetto di frequenza, ogni unità vale 1. */
final class NeutralViewerMultiplier implements ViewerMultiplier
{
    public function multipliers(DeliveryPlan $plan, ?User $user, CarbonImmutable $now): array
    {
        return [];
    }
}
