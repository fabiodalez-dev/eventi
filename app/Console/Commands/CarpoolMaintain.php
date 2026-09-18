<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Carpool\CarpoolLifecycle;
use App\Services\Carpool\CommunityDelivery;
use Illuminate\Console\Command;

final class CarpoolMaintain extends Command
{
    protected $signature = 'carpool:maintain';

    protected $description = 'Reconcile ride eligibility, expirations, search alerts and reminders';

    public function handle(CarpoolLifecycle $lifecycle, CommunityDelivery $delivery): int
    {
        $lifecycle->tick();
        $delivery->deliver();

        return self::SUCCESS;
    }
}
