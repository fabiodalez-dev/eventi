<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\Enums\RideRequestStatus;
use App\Models\RideConversation;
use App\Models\RideMessage as Message;
use App\Models\User;
use App\Queries\CarpoolQuery;
use Illuminate\Support\Facades\DB;
use Musonza\Chat\Models\Conversation;
use Musonza\Chat\Models\Participation;

final class RideChat
{
    public function __construct(private CarpoolAccess $access, private CarpoolAuditLog $audit) {}

    public function writable(User $user, RideConversation $chat): bool
    {
        $request = $chat->rideRequest;
        $offer = $request->offer;

        return config('carpool.chat_enabled') && $this->access->canReadChat($user, $chat)
            && $chat->read_only_at === null && $request->status === RideRequestStatus::Accepted
            && $request->user && $offer->driver && $this->access->eligible($request->user, false) && $this->access->eligible($offer->driver, false)
            && ! $this->access->blocked($request->user, $offer->driver)
            && app(CarpoolQuery::class)->now($offer->occurrence)->lt($offer->departure_at->addHours(config()->integer('carpool.chat_hours')))
            && app(CarpoolQuery::class)->visible($offer->occurrence) && ! app(CarpoolQuery::class)->changed($offer);
    }

    public function send(User $user, RideConversation $chat, string $body, string $key): int
    {
        return app(CarpoolCommands::class)->run($user, $key, 'message', ['chat' => $chat->id, 'body' => $body],
            $chat->rideRequest->offer->occurrence_id, function (User $actor) use ($chat, $body): int {
                $chat = RideConversation::whereKey($chat->id)->lockForUpdate()->firstOrFail();
                abort_unless($this->writable($actor, $chat), 403, __('carpool.errors.chat_closed'));
                $conversation = Conversation::findOrFail($chat->conversation_id);
                $participation = Participation::where('conversation_id', $conversation->id)->where('messageable_id', $actor->id)
                    ->where('messageable_type', $actor->getMorphClass())->firstOrFail();
                $message = (new \Musonza\Chat\Models\Message)->send($conversation, $body, $participation);
                $messageId = (int) $message->getKey();
                $chat->touch();
                $this->audit->record($actor, 'message_sent', $chat, ['message_id' => $messageId]);
                $recipient = $chat->rideRequest->user_id === $actor->id ? $chat->rideRequest->offer->driver : $chat->rideRequest->user;
                app(CommunityNotices::class)->send($recipient, 'chat', 'message', 'chat', $chat->id, 'message:'.$messageId, $messageId);

                return $messageId;
            });
    }

    /** @return list<array<string, mixed>> */
    public function messages(User $user, RideConversation $chat, int $after = 0, ?int $before = null): array
    {
        abort_unless($this->access->canReadChat($user, $chat), 403);
        $query = Message::where('conversation_id', $chat->conversation_id)->with('participation')
            ->where('id', '>', $after)->when($before !== null, fn ($q) => $q->where('id', '<', $before));
        // Initial load returns the latest page; incremental polling returns ascending new messages.
        $messages = ($after > 0 ? $query->orderBy('id') : $query->orderByDesc('id'))->limit(50)->get()->sortBy('id');

        return $messages->map(fn (Message $message): array => ['id' => (int) $message->getKey(), 'body' => $message->body,
            'mine' => (int) $message->participation?->getAttribute('messageable_id') === $user->id, 'created_at' => $message->created_at->toIso8601String()])->values()->all();
    }

    /** @param array<string, mixed> $data */
    public function preferences(User $user, RideConversation $chat, array $data): void
    {
        abort_unless($this->access->canReadChat($user, $chat), 403);
        DB::transaction(function () use ($user, $chat, $data): void {
            RideConversation::whereKey($chat->id)->lockForUpdate()->firstOrFail();
            $values = array_intersect_key($data, array_flip(['muted', 'archived']));
            if (isset($data['read_through_id'])) {
                $watermark = (int) $data['read_through_id'];
                abort_unless($watermark === 0 || Message::where('conversation_id', $chat->conversation_id)->whereKey($watermark)->exists(), 422);
                $current = DB::table('ride_chat_preferences')->where('ride_conversation_id', $chat->id)->where('user_id', $user->id)->value('read_through_id');
                $values['read_through_id'] = max((int) $current, $watermark);
                DB::table('chat_message_notifications')->where('conversation_id', $chat->conversation_id)->where('messageable_id', $user->id)
                    ->where('messageable_type', $user->getMorphClass())->where('message_id', '<=', $watermark)->update(['is_seen' => true, 'updated_at' => now()]);
                $user->unreadNotifications()->where('data->category', 'chat')->where('data->entity_id', $chat->id)
                    ->where('data->message_id', '<=', $watermark)->update(['read_at' => now()]);
            }
            DB::table('ride_chat_preferences')->updateOrInsert(['ride_conversation_id' => $chat->id, 'user_id' => $user->id], $values);
        });
    }
}
