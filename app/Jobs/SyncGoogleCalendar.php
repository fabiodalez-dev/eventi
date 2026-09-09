<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Calendar\GoogleCalendarSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncGoogleCalendar implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 540;

    public int $uniqueFor = 600;

    public function __construct(public int $userId)
    {
        $this->onConnection('google_calendar')->onQueue('google-calendar');
    }

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(GoogleCalendarSync $sync): void
    {
        $sync->run($this->userId);
    }
}
