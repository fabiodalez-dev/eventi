<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Http\Resources\V1\BookingResource;
use App\Models\AvailabilityAlert;
use App\Models\Booking;
use App\Models\BookingOpeningAlert;
use App\Models\CatalogReview;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\ConsentLog;
use App\Models\Device;
use App\Models\EditionAlert;
use App\Models\EventCollection;
use App\Models\EventComment;
use App\Models\EventPoll;
use App\Models\EventPollVote;
use App\Models\Follow;
use App\Models\NotificationLog;
use App\Models\PresenceShare;
use App\Models\SavedEvent;
use App\Models\SavedSearch;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Carpool\CarpoolAccount;
use App\Services\RememberedLocation;
use App\Support\Api\ApiDate;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Overtrue\LaravelFollow\Followable;

/**
 * Portabilità dei dati (§15.9, `GET /v1/me/export`).
 *
 * Il diritto è quello di ricevere **ciò che si è dato**, in una forma leggibile
 * da un programma: profilo (con nome, cognome e posizione ricordata), date
 * salvate, ciò che si segue, ricerche, raccolte, commenti, link «sono qui»,
 * avvisi, sondaggi e voti, scelte sui cookie, dispositivi registrati, archivio
 * delle notifiche e cronaca degli invii. Non ci finisce
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
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
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
                // La posizione ricordata è precisa e serve agli avvisi «vicino a te»: chi la chiede deve vederla.
                'remembered_location' => $this->rememberedLocation($user, $timezone),
            ],
            'saved_searches' => SavedSearch::query()->where('user_id', $user->id)->get()
                ->map(static fn (SavedSearch $search): array => [
                    'name' => $search->name,
                    'city_id' => (int) $search->city_id,
                    'filters' => $search->filters,
                    'created_at' => ApiDate::attribute($search, 'created_at', $timezone),
                ])->all(),
            'collections' => EventCollection::query()->where('user_id', $user->id)->with('items')->get()
                ->map(static fn (EventCollection $collection): array => [
                    'name' => $collection->name,
                    'description' => $collection->description,
                    'is_public' => (bool) $collection->is_public,
                    'items' => $collection->items->map(static fn ($item): array => ['occurrence_id' => (int) $item->occurrence_id, 'note' => $item->note])->all(),
                    'created_at' => ApiDate::attribute($collection, 'created_at', $timezone),
                ])->all(),
            'event_comments' => EventComment::query()->where('user_id', $user->id)->get(['id', 'event_id', 'parent_id', 'body', 'status', 'created_at'])
                ->map(static fn (EventComment $comment): array => [
                    'id' => (int) $comment->id,
                    'event_id' => (int) $comment->event_id,
                    'parent_id' => $comment->parent_id === null ? null : (int) $comment->parent_id,
                    'body' => $comment->body,
                    'status' => $comment->getRawOriginal('status'),
                    'created_at' => ApiDate::attribute($comment, 'created_at', $timezone),
                ])->all(),
            'presence_shares' => PresenceShare::query()->where('user_id', $user->id)->get()
                ->map(static fn (PresenceShare $share): array => [
                    'occurrence_id' => (int) $share->occurrence_id,
                    'display_name' => $share->display_name,
                    'note' => $share->note,
                    'expires_at' => ApiDate::attribute($share, 'expires_at', $timezone),
                    'revoked_at' => ApiDate::attribute($share, 'revoked_at', $timezone),
                ])->all(),
            'alerts' => [
                'availability' => AvailabilityAlert::query()->where('user_id', $user->id)->pluck('occurrence_id')->map(static fn (mixed $id): int => (int) $id)->all(),
                'booking_opening' => BookingOpeningAlert::query()->where('user_id', $user->id)->pluck('occurrence_id')->map(static fn (mixed $id): int => (int) $id)->all(),
                'edition' => EditionAlert::query()->where('user_id', $user->id)->pluck('event_id')->map(static fn (mixed $id): int => (int) $id)->all(),
            ],
            'polls' => EventPoll::query()->where('user_id', $user->id)->get(['id', 'event_id', 'note', 'closes_at', 'created_at'])
                ->map(static fn (EventPoll $poll): array => [
                    'id' => (int) $poll->id,
                    'event_id' => (int) $poll->event_id,
                    'note' => $poll->note,
                    'closes_at' => ApiDate::attribute($poll, 'closes_at', $timezone),
                    'created_at' => ApiDate::attribute($poll, 'created_at', $timezone),
                ])->all(),
            'poll_votes' => EventPollVote::query()->where('user_id', $user->id)->get(['event_poll_id', 'event_poll_option_id', 'created_at'])
                ->map(static fn (EventPollVote $vote): array => [
                    'poll_id' => (int) $vote->event_poll_id,
                    'option_id' => (int) $vote->event_poll_option_id,
                    'created_at' => ApiDate::attribute($vote, 'created_at', $timezone),
                ])->all(),
            'consent_logs' => ConsentLog::query()->where('user_id', $user->id)->get()
                ->map(static fn (ConsentLog $log): array => [
                    'action' => $log->action->value,
                    'choices' => $log->choices,
                    'policy_version' => $log->policy_version,
                    'created_at' => ApiDate::attribute($log, 'created_at', $timezone),
                ])->all(),
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

    /**
     * @return array{lat: float, lng: float, saved_at: string|null, expires_at: string|null}|null
     */
    private function rememberedLocation(User $user, string $timezone): ?array
    {
        $position = $user->remembered_location;

        if (! app(RememberedLocation::class)->valid($position)) {
            return null;
        }

        return [
            'lat' => (float) $position['lat'],
            'lng' => (float) $position['lng'],
            'saved_at' => ApiDate::instant(Carbon::createFromTimestamp($position['saved_at']), $timezone),
            'expires_at' => ApiDate::instant(Carbon::createFromTimestamp($position['expires_at']), $timezone),
        ];
    }
}
