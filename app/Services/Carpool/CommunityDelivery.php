<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\DTOs\QuietHours;
use App\Enums\NotificationDelivery;
use App\Enums\RideRequestStatus;
use App\Models\CatalogReview;
use App\Models\CommunityPost;
use App\Models\RideConversation;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideSearch;
use App\Models\User;
use App\Notifications\CommunityPush;
use App\Queries\CarpoolQuery;
use App\Services\Community\CommunityAccess;
use App\Services\Notifications\ChannelSelector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\WebPush\WebPushChannel;
use Throwable;

final class CommunityDelivery
{
    /** @param array<string, mixed> $data */
    public function allowed(User $user, array $data): bool
    {
        if (! $user->hasVerifiedEmail() || $user->trashed()) {
            return false;
        }
        $category = $data['category'] ?? '';
        if ($category !== 'social' && app(CarpoolAccess::class)->profile($user)?->push_enabled === false) {
            return false;
        }
        if (in_array($user->notificationPreferences()->delivery, [NotificationDelivery::Mail, NotificationDelivery::Database], true)) {
            return false;
        }
        if ($category === 'chat') {
            $chat = RideConversation::find($data['entity_id']);

            return $chat !== null && app(RideChat::class)->writable($user, $chat)
                && ! DB::table('ride_chat_preferences')->where('ride_conversation_id', $chat->id)->where('user_id', $user->id)->value('muted');
        }
        if ($category === 'carpool') {
            if (($data['entity'] ?? '') === 'request') {
                $ride = RideRequest::find($data['entity_id']);
                if (! $ride || ! app(CarpoolAccess::class)->participant($user, $ride)) {
                    return false;
                }

                return match ($data['kind']) {
                    'accepted', 'reminder' => $ride->status === RideRequestStatus::Accepted && app(CarpoolAccess::class)->eligible($user, false)
                        && app(CarpoolQuery::class)->operational($ride->offer),
                    'requested' => $ride->status === RideRequestStatus::Pending && app(CarpoolQuery::class)->operational($ride->offer),
                    default => true,
                };
            }
            if (($data['entity'] ?? '') === 'offer') {
                $offer = RideOffer::find($data['entity_id']);

                if (! $offer || ! app(CarpoolAccess::class)->canViewOffer($user, $offer)) {
                    return false;
                }
                if (in_array($data['kind'], ['match', 'suggestion'], true)) {
                    $search = RideSearch::where('user_id', $user->id)->find($data['search_id'] ?? 0);

                    return $search && ($data['kind'] !== 'match' || $search->alerts_enabled) && app(RideDiscovery::class)->matches($search, $offer);
                }
                if ($data['kind'] === 'pending') {
                    return app(CarpoolQuery::class)->operational($offer) && $offer->requests()->where('status', RideRequestStatus::Pending)->exists();
                }

                return true;
            }

            return true;
        }
        if ($category === 'social') {
            if (($data['kind'] ?? '') === 'catalog_review_moderated' && in_array($data['entity'] ?? '', ['venue', 'organizer'], true)) {
                return $user->canParticipateInCommunity() && CatalogReview::where('user_id', $user->id)->where('reviewable_type', $data['entity'])->where('reviewable_id', $data['entity_id'])->exists();
            }
            $path = $data['path'] ?? '';
            if (preg_match('~^/bacheca/post/(\d+)$~', $path, $matches)) {
                $post = CommunityPost::find((int) $matches[1]);

                return $post !== null && app(CommunityAccess::class)->canViewPost($user, $post);
            }
            // Gli avvisi di nuovi follower portano all'elenco personale, visibile solo a chi è verificato.
            if ($path === '/persone-che-mi-seguono') {
                return $user->canParticipateInCommunity();
            }
            if (preg_match('~^/persone/([a-z0-9_]+)$~', $path, $matches)) {
                return app(CommunityAccess::class)->profiles($user)->where('handle', $matches[1])->exists();
            }

            return false;
        }

        return false;
    }

    public function deliver(int $limit = 100): int
    {
        $done = 0;
        for ($i = 0; $i < $limit; $i++) {
            $row = DB::transaction(function () {
                $row = DB::table('community_delivery_outbox')->whereNull('delivered_at')->where('available_at', '<=', now())->where('attempts', '<', 8)->orderBy('id')->lock('for update skip locked')->first();
                if ($row) {
                    DB::table('community_delivery_outbox')->where('id', $row->id)->update(['available_at' => now()->addMinutes(5), 'attempts' => $row->attempts + 1]);
                }

                return $row;
            });
            if (! $row) {
                break;
            }
            $user = User::find($row->user_id);
            $data = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            try {
                if ($user && $this->allowed($user, $data)) {
                    $local = CarbonImmutable::now($user->timezone ?: 'Europe/Rome');
                    $quiet = QuietHours::fromUser($user);
                    if ($quiet && $quiet->contains($local)) {
                        DB::table('community_delivery_outbox')->where('id', $row->id)->update(['available_at' => $quiet->endsAfter($local)->utc(), 'attempts' => $row->attempts]);

                        continue;
                    }
                    $data = app(UnifiedNotifications::class)->destination($data);
                    $selector = app(ChannelSelector::class);
                    $channels = array_filter([$selector->webConfigured() ? WebPushChannel::class : null, $selector->fcmConfigured() ? FcmChannel::class : null]);
                    foreach ($channels as $channel) {
                        if (in_array($channel, $data['delivered_channels'] ?? [], true)) {
                            continue;
                        }
                        $user->notifyNow(new CommunityPush($data, $row->notification_id, $channel));
                        $data['delivered_channels'][] = $channel;
                        DB::table('community_delivery_outbox')->where('id', $row->id)->update(['payload' => json_encode($data, JSON_THROW_ON_ERROR)]);
                    }
                }
                DB::table('community_delivery_outbox')->where('id', $row->id)->update(['delivered_at' => now(), 'last_error' => null]);
                $done++;
            } catch (Throwable $error) {
                DB::table('community_delivery_outbox')->where('id', $row->id)->update(['last_error' => class_basename($error), 'available_at' => now()->addMinutes(min(60, 2 ** min(6, $row->attempts)))]);
            }
        }

        return $done;
    }
}
