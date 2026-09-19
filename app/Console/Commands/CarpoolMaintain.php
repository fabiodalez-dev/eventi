<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Carpool\CarpoolLifecycle;
use App\Services\Carpool\CommunityDelivery;
use Illuminate\Console\Command;
use Throwable;

final class CarpoolMaintain extends Command
{
    protected $signature = 'carpool:maintain';

    protected $description = 'Reconcile ride eligibility, expirations, search alerts and reminders';

    public function handle(CarpoolLifecycle $lifecycle, CommunityDelivery $delivery): int
    {
        // La coda porta anche le notifiche della community: un errore nella
        // riconciliazione dei passaggi non deve bloccarne la consegna.
        $status = self::SUCCESS;
        try {
            $lifecycle->tick();
        } catch (Throwable $e) {
            report($e);
            $status = self::FAILURE;
        } finally {
            $delivery->deliver();
        }

        return $status;
    }
}
