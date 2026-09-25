<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Http\Resources\V1\BookingResource;
use App\Models\Booking;
use App\Models\CatalogReview;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\Device;
use App\Models\Follow;
use App\Models\NotificationLog;
use App\Models\SavedEvent;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Carpool\CarpoolAccount;
use App\Support\Api\ApiDate;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Overtrue\LaravelFollow\Followable;

/**
 * Portabilità dei dati (§15.9, `GET /v1/me/export`).
 *
 * Il diritto è quello di ricevere **ciò che si è dato**, in una forma leggibile
 * da un programma: profilo, date salvate, ciò che si segue, dispositivi
 * registrati, archivio delle notifiche e cronaca degli invii. Non ci finisce
 * nulla di derivato o di editoriale — un punteggio calcolato su una persona
 * non è un dato che quella persona ha fornito, ed esporlo insegnerebbe solo a
 * manipolarlo.
 */
final class AccountExport
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user): array
    {
        $timezone = (string) $user->timezone;

        return [
            'carpool' => app(CarpoolAccount::class)->export($user),
            'community' => [
                'profile' => $user->communityProfile?->only(['handle', 'display_name', 'bio', 'visibility', 'indexable']),
                'venue_ids' => $user->communityProfile?->venues()->pluck('venues.id')->all() ?? [],
                'whatsapp_phone' => $user->whatsapp_phone,
                'whatsapp_verified_at' => $user->whatsapp_verified_at?->toIso8601String(),
                'attendances' => DB::table('community_attendances')->where('user_id', $user->id)->get(['occurrence_id', 'created_at'])->toArray(),
                'posts' => CommunityPost::query()->where('user_id', $user->id)->get()->toArray(),
                'comments' => CommunityComment::query()->where('user_id', $user->id)->get()->toArray(),
                'followings' => $user->followings()->get(['followable_id', 'accepted_at'])->toArray(),
                // Chi segue l'account: `followers()` restituisce utenti, qui servono le righe della relazione.
                'followers' => Followable::query()->where('followable_type', $user->getMorphClass())->where('followable_id', $user->id)
                    ->get(['user_id', 'accepted_at'])->toArray(),
                'blocks' => UserBlock::query()->where('user_id', $user->id)->pluck('blocked_user_id')->all(),
            ],
            'exported_at' => ApiDate::instant(now(), $timezone),
            'bookings' => Booking::query()->where('user_id', $user->id)->with('tickets')->get()
                ->map(fn ($booking) => BookingResource::toArray($booking))->all(),
            'profile' => [
                'id' => (int) $user->getKey(),
                'name' => $user->name,
                'email' => (string) $user->email,
                'email_verified_at' => ApiDate::instant($user->email_verified_at, $timezone),
                'timezone' => $timezone,
                'locale' => (string) $user->locale,
                'appearance' => $user->appearance,
                'notification_preferences' => $user->notificationPreferences()->toArray(),
                'content_preferences' => app(ContentPreferences::class)->selection($user),
                'daily_digest_time' => $user->daily_digest_time,
                'quiet_hours' => $user->quiet_hours,
                'marketing_opt_in_at' => ApiDate::instant($user->marketing_opt_in_at, $timezone),
                'created_at' => ApiDate::attribute($user, 'created_at', $timezone),
            ],
            'catalog_reviews' => CatalogReview::where('user_id', $user->id)->with('ratings')->get()->map(fn (CatalogReview $review): array => [...$review->only(['reviewable_type', 'reviewable_id', 'status', 'created_at', 'updated_at']), 'rating' => $review->rating, 'body' => $review->body])->all(),
            'saved_events' => $user->savedEvents()
                ->with('occurrence.event')
                ->get()
                ->map(static fn (SavedEvent $saved): array => [
                    'occurrence_id' => (int) $saved->occurrence_id,
                    'event_title' => $saved->occurrence?->event->title,
                    'starts_at' => ApiDate::instant($saved->occurrence?->starts_at, $timezone),
                    'visibility' => $saved->visibility->value,
                    'saved_at' => ApiDate::attribute($saved, 'created_at', $timezone),
                ])
                ->all(),
            'follows' => $user->follows()
                ->get()
                ->map(static fn (Follow $follow): array => [
                    'type' => (string) $follow->followable_type,
                    'id' => (int) $follow->followable_id,
                    'notify' => (bool) $follow->notify,
                    'followed_at' => ApiDate::attribute($follow, 'created_at', $timezone),
                ])
                ->all(),
            'devices' => $user->devices()
                ->get()
                ->map(static fn (Device $device): array => [
                    'platform' => $device->platform->value,
                    'app_version' => $device->app_version,
                    'locale' => $device->locale,
                    'last_seen_at' => ApiDate::instant($device->last_seen_at, $timezone),
                    'revoked_at' => ApiDate::instant($device->revoked_at, $timezone),
                ])
                ->all(),
            'notifications' => $user->notifications()
                ->get()
                ->map(static fn (DatabaseNotification $notification): array => [
                    'type' => (string) $notification->type,
                    'data' => $notification->data,
                    'read_at' => ApiDate::instant($notification->read_at, $timezone),
                    'created_at' => ApiDate::attribute($notification, 'created_at', $timezone),
                ])
                ->all(),
            'notification_log' => $user->notificationLogs()
                ->get()
                ->map(static fn (NotificationLog $log): array => [
                    'type' => (string) $log->type,
                    'channel' => $log->channel->value,
                    'sent_at' => ApiDate::instant($log->sent_at, $timezone),
                    'opened_at' => ApiDate::instant($log->opened_at, $timezone),
                    'clicked_at' => ApiDate::instant($log->clicked_at, $timezone),
                ])
                ->all(),
        ];
    }
}
