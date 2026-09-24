<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Ticketing\GoogleWallet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeactivateWalletPass implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public function __construct(public string $objectId)
    {
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(GoogleWallet $wallet): void
    {
        $wallet->deactivate($this->objectId);
    }
}
