<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\Models\CarpoolCase;
use App\Models\CarpoolCaseMessage;
use App\Models\CarpoolProfile;
use App\Models\RideConversation;
use App\Models\RideFeedback;
use App\Models\RideMessage as Message;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideReview;
use App\Models\RideSearch;
use App\Models\RideTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CarpoolAccount
{
    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        $chats = RideConversation::whereIn('ride_request_id', RideRequest::where('user_id', $user->id)->orWhereIn('ride_offer_id', RideOffer::where('driver_id', $user->id)->select('id'))->select('id'))
            ->with('rideRequest.offer')->get()->filter(fn ($chat) => app(CarpoolRetention::class)->readable($chat));
        $messages = Message::whereIn('conversation_id', $chats->pluck('conversation_id'))->whereIn('participation_id', DB::table('chat_participation')->where('messageable_id', $user->id)->where('messageable_type', $user->getMorphClass())->select('id'))
            ->orderBy('id')->get()->map(fn ($message) => $message->only(['id', 'conversation_id', 'body', 'created_at']))->all();

        return [
            'profile' => CarpoolProfile::where('user_id', $user->id)->first()?->only(['adult_declared_at', 'adult_version', 'terms_version', 'terms_hash', 'terms_accepted_at', 'driver_declared_at', 'push_enabled']),
            'offers' => RideOffer::where('driver_id', $user->id)->get()->toArray(),
            'requests' => RideRequest::where('user_id', $user->id)->with('offer')->get()->map(fn ($ride) => [...$ride->only(['id', 'ride_offer_id', 'seats', 'companions_adult', 'stop_index', 'status', 'created_at', 'accepted_at', 'closed_at']), 'note' => app(CarpoolRetention::class)->requestNoteReadable($ride) ? $ride->note : null])->all(),
            'messages_authored' => $messages,
            'searches' => RideSearch::where('user_id', $user->id)->get()->toArray(),
            'templates' => RideTemplate::where('user_id', $user->id)->get()->toArray(),
            'reviews' => RideReview::where('user_id', $user->id)->get()->toArray(),
            'feedback' => RideFeedback::where('user_id', $user->id)->get()->toArray(),
            'cases' => CarpoolCase::where('reporter_id', $user->id)->get()->map(fn ($case) => $case->only(['id', 'reason', 'body', 'status', 'created_at', 'closed_at']))->all(),
            'assistance_messages' => CarpoolCaseMessage::where('internal', false)->where(fn ($q) => $q->where('author_id', $user->id)->orWhere('recipient_id', $user->id))->get()->map(fn ($message) => $message->only(['id', 'carpool_case_id', 'body', 'created_at']))->all(),
        ];
    }

    public function erase(User $user): void
    {
        app(CarpoolLifecycle::class)->reconcileUser($user->id);
        RideReview::where('user_id', $user->id)->get()->each(function ($review): void {
            $review->update(['rating' => null, 'body' => null]);
            $review->delete();
        });
        RideTemplate::where('user_id', $user->id)->delete();
        RideSearch::where('user_id', $user->id)->delete();
        CarpoolProfile::where('user_id', $user->id)->delete();
        DB::table('community_delivery_outbox')->where('user_id', $user->id)->delete();
        DB::table('community_notification_receipts')->where('user_id', $user->id)->delete();
        DB::table('carpool_commands')->where('user_id', $user->id)->delete();
        $user->notifications()->delete();
    }
}
