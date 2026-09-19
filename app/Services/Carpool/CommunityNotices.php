<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\Models\Organizer;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommunityNotices
{
    public function ride(User $user, string $kind, RideRequest $request, string $suffix = ''): void
    {
        $this->send($user, 'carpool', $kind, 'request', $request->id, 'ride:'.$kind.':'.$request->id.$suffix);
    }

    public function send(User $user, string $category, string $kind, string $entity, int $id, string $dedupe, ?int $messageId = null, ?int $searchId = null): void
    {
        if (DB::table('community_delivery_outbox')->where('dedupe_key', $dedupe.':'.$user->id)->exists()) {
            return;
        }
        $uuid = (string) Str::uuid();
        $payload = ['category' => $category, 'kind' => $kind, 'entity' => $entity, 'entity_id' => $id,
            'message_id' => $messageId, 'search_id' => $searchId, 'title' => __('carpool.notifications.'.$kind), 'body' => __('carpool.notifications.open'),
            'url' => $this->destination($entity, $id)];
        $user->notifications()->create(['id' => $uuid, 'type' => $category.'.'.$kind, 'data' => $payload, 'read_at' => null]);
        DB::table('community_delivery_outbox')->insert(['user_id' => $user->id, 'notification_id' => $uuid,
            'dedupe_key' => $dedupe.':'.$user->id, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function destination(string $entity, int $id): string
    {
        return match ($entity) {
            'venue' => ($venue = Venue::find($id)) ? route('venues.show', $venue).'#recensioni' : route('community.inbox'),
            'organizer' => ($organizer = Organizer::find($id)) ? route('organizers.show', $organizer).'#recensioni' : route('community.inbox'),
            'driver' => route('carpool.reviews', $id),
            'request' => route('carpool.request', $id),
            'offer' => route('carpool.offer', $id),
            'chat' => route('carpool.chat', $id),
            'case' => route('carpool.case', $id),
            default => route('carpool.index'),
        };
    }

    /** @return array<string, int|string> */
    public function summary(User $user): array
    {
        $unread = $user->unreadNotifications()->where(fn ($q) => $q->whereNull('data->category')->orWhere('data->category', '!=', 'chat'))->count();
        $chats = DB::table('ride_chat_preferences as p')->join('ride_conversations as c', 'c.id', '=', 'p.ride_conversation_id')
            ->join('ride_requests as r', 'r.id', '=', 'c.ride_request_id')->join('ride_offers as o', 'o.id', '=', 'r.ride_offer_id')
            ->whereRaw('COALESCE(c.read_only_at, o.closed_at, DATE_ADD(o.departure_at, INTERVAL ? HOUR)) > ?', [config()->integer('carpool.chat_hours'), now()->subDays(config()->integer('carpool.message_retention_days'))])
            ->join('chat_messages as m', 'm.conversation_id', '=', 'c.conversation_id')
            ->join('chat_participation as sender', 'sender.id', '=', 'm.participation_id')
            ->where('p.user_id', $user->id)->whereNull('c.hidden_at')->whereNull('c.purged_at')
            ->whereColumn('m.id', '>', 'p.read_through_id')->where('sender.messageable_id', '!=', $user->id)->distinct()->count('c.id');
        $pending = RideRequest::where('status', 'pending')->whereHas('offer', fn ($q) => $q->where('driver_id', $user->id)
            ->whereIn('status', ['open', 'closed'])->where('departure_at', '>', now()))->count();

        return ['unread' => $unread, 'conversations' => $chats, 'total' => $unread + $chats, 'pending' => $pending,
            'watermark' => app(UnifiedNotifications::class)->watermark($user), 'version' => (string) microtime(true)];
    }
}
