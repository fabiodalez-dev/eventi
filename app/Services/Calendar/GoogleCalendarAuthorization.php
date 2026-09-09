<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Jobs\SyncGoogleCalendar;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class GoogleCalendarAuthorization
{
    /** @param array<string, mixed> $pending */
    public function complete(int $userId, string $code, array $pending): void
    {
        $tokens = app(GoogleCalendarClient::class)->exchange($code, $pending['verifier']);
        Cache::lock('google-calendar-'.$userId, 600)->block(3, function () use ($userId, $pending, $tokens): void {
            if (! User::whereKey($userId)->exists()) {
                try {
                    app(GoogleCalendarClient::class)->revoke($tokens['refresh_token']);
                } catch (\Throwable) {
                    // No credentials may survive a concurrent account erasure.
                }
                throw new RuntimeException('google_account_unavailable');
            }
            $connection = GoogleCalendarConnection::firstOrNew(['user_id' => $userId]);
            if ($connection->google_subject !== $tokens['subject']) {
                $connection->calendar_id = null;
                $connection->synced_at = null;
                $connection->event_count = 0;
            }
            $connection->fill(['city_id' => $pending['city_id'], 'google_subject' => $tokens['subject'],
                'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'],
                'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)), 'selection' => $pending['selection'],
                'enabled' => true, 'error_code' => null])->save();
        });
        SyncGoogleCalendar::dispatch($userId);
    }

    /** @param array<string, mixed> $selection */
    public function update(int $userId, array $selection): void
    {
        Cache::lock('google-calendar-'.$userId, 600)->block(3, function () use ($userId, $selection): void {
            GoogleCalendarConnection::where('user_id', $userId)->where('enabled', true)->firstOrFail()
                ->update(['selection' => $selection]);
        });
        SyncGoogleCalendar::dispatch($userId);
    }
}
