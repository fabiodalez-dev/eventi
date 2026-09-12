<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\Device;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

final class DeviceLimit
{
    /** Call inside a transaction after locking the owning user. Keep the renewed device. */
    public function enforce(User $user, Device $current): void
    {
        $keep = max(1, config()->integer('account.max_devices_per_user'));
        $obsolete = $user->devices()->whereKeyNot($current->getKey())
            ->orderByRaw('revoked_at is null desc')->orderByDesc('last_seen_at')->orderByDesc('id')
            ->skip($keep - 1)->take(PHP_INT_MAX)->pluck('id');
        if ($obsolete->isEmpty()) {
            return;
        }
        PersonalAccessToken::query()->whereIn('device_id', $obsolete)->delete();
        $user->devices()->whereIn('id', $obsolete)->delete();
    }
}
