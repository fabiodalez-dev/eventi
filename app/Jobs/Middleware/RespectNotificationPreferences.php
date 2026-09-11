<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\DTOs\QuietHours;
use App\Models\User;
use App\Notifications\Scheduled\ScheduledMessage;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Notifications\SendQueuedNotifications;

/** Recheck at delivery too: a delayed queue can enter the user's quiet hours. */
final class RespectNotificationPreferences
{
    public function handle(SendQueuedNotifications $job, Closure $next): void
    {
        $notification = $job->notification;
        $user = $job->notifiables->first();
        if (! $notification instanceof ScheduledMessage || ! $user instanceof User) {
            $next($job);

            return;
        }
        $user = $user->fresh();
        if (! $user || ! $notification->shouldSend($user, (string) ($job->channels[0] ?? 'database'))) {
            return;
        }
        if ($notification->message->type->respectsQuietHours()) {
            $quiet = QuietHours::fromUser($user);
            $now = CarbonImmutable::now($user->timezone ?: config('app.timezone'));
            if ($quiet && $quiet->contains($now)) {
                $job->release($quiet->endsAfter($now));

                return;
            }
        }
        $next($job);
    }
}
