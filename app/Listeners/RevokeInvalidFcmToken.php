<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Arr;
use Kreait\Firebase\Messaging\SendReport;
use NotificationChannels\Fcm\FcmChannel;

final class RevokeInvalidFcmToken
{
    public function handle(NotificationFailed $event): void
    {
        if ($event->channel !== FcmChannel::class) {
            return;
        }

        $report = Arr::get($event->data, 'report');

        if (! $report instanceof SendReport) {
            return;
        }

        $token = $report->target()->value();

        if ($report->messageTargetWasInvalid() || $report->messageWasSentToUnknownToken()) {
            Device::query()
                ->where('token_hash', hash('sha256', $token))
                ->update(['revoked_at' => CarbonImmutable::now()]);
        }
    }
}
