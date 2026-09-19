<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class UnifiedNotifications
{
    public function archived(DatabaseNotification $notification): void
    {
        if (! Schema::hasTable('community_notification_receipts') || ! in_array($notification->notifiable_type, ['user', User::class], true)) {
            return;
        }
        DB::table('community_notification_receipts')->insertOrIgnore(['user_id' => $notification->notifiable_id, 'notification_id' => $notification->id]);
        if (($notification->data['category'] ?? null) === 'social' && isset($notification->data['path'])) {
            DB::table('community_delivery_outbox')->insertOrIgnore(['user_id' => $notification->notifiable_id,
                'notification_id' => $notification->id, 'dedupe_key' => 'social:'.$notification->id,
                'payload' => json_encode($notification->data, JSON_THROW_ON_ERROR), 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function watermark(User $user): int
    {
        return (int) DB::table('community_notification_receipts')->where('user_id', $user->id)->max('id');
    }

    public function readAll(User $user, ?int $through = null): int
    {
        $through = min($through ?? $this->watermark($user), $this->watermark($user));

        return $user->unreadNotifications()->where(fn ($q) => $q->whereNull('data->category')->orWhere('data->category', '!=', 'chat'))
            ->whereIn('id', DB::table('community_notification_receipts')->where('user_id', $user->id)->where('id', '<=', $through)->select('notification_id'))
            ->update(['read_at' => now()]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function destination(array $data): array
    {
        if (in_array($data['category'] ?? null, ['carpool', 'chat', 'social'], true) && isset($data['entity'], $data['entity_id'])) {
            $data['url'] = app(CommunityNotices::class)->destination($data['entity'], (int) $data['entity_id']);
        }
        if (($data['category'] ?? null) === 'social' && isset($data['path']) && preg_match('~^/(persone/[a-z0-9_]+|bacheca/post/[0-9]+|persone-che-mi-seguono)$~', $data['path'])) {
            $data['url'] = url($data['path']);
        }

        return $data;
    }
}
