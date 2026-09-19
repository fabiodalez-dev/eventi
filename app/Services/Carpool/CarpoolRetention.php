<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\Models\CarpoolAudit;
use App\Models\CarpoolCase;
use App\Models\Report;
use App\Models\RideConversation;
use App\Models\RideOffer;
use App\Models\RideRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class CarpoolRetention
{
    public function readable(RideConversation $chat): bool
    {
        $closed = $chat->read_only_at ?? $chat->rideRequest->offer->closed_at ?? $chat->rideRequest->offer->departure_at->addHours(config()->integer('carpool.chat_hours'));

        return $chat->purged_at === null && $closed->addDays(config()->integer('carpool.message_retention_days'))->isFuture();
    }

    public function requestNoteReadable(RideRequest $ride): bool
    {
        $closed = $ride->closed_at ?? $ride->offer->closed_at ?? $ride->offer->departure_at->addHours(config()->integer('carpool.chat_hours'));

        return $closed->addDays(config()->integer('carpool.message_retention_days'))->isFuture();
    }

    /**
     * Pratiche che trattengono le prove: quelle con un blocco esplicito e
     * quelle ancora aperte, che senza trascrizione non si potrebbero istruire.
     *
     * @return Builder<CarpoolCase>
     */
    private function holdingCases(): Builder
    {
        return CarpoolCase::where(fn ($q) => $q->whereNull('closed_at')->orWhere('hold_until', '>', now()));
    }

    public function heldRequest(RideRequest $request): bool
    {
        return $this->holdingCases()->where(fn ($q) => $q->where('ride_request_id', $request->id)
            ->orWhere(fn ($q) => $q->whereNull('ride_request_id')->where('ride_offer_id', $request->ride_offer_id)))->exists();
    }

    /** @return Builder<CarpoolAudit> */
    private function unheldAudits(): Builder
    {
        $held = $this->holdingCases()->get();
        $cases = $held->pluck('id');
        $offers = $held->pluck('ride_offer_id')->filter();
        $requests = $held->pluck('ride_request_id')->filter()->merge(RideRequest::whereIn('ride_offer_id', $held->whereNull('ride_request_id')->pluck('ride_offer_id')->filter())->pluck('id'))->unique();
        $reviews = $held->where('social_type', 'ride_review')->pluck('social_id');
        $chats = RideConversation::whereIn('ride_request_id', $requests)->pluck('id');

        return CarpoolAudit::whereNot(fn ($q) => $q->where(fn ($q) => $q->where('subject_type', 'CarpoolCase')->whereIn('subject_id', $cases))
            ->orWhere(fn ($q) => $q->where('subject_type', 'RideOffer')->whereIn('subject_id', $offers))
            ->orWhere(fn ($q) => $q->where('subject_type', 'RideRequest')->whereIn('subject_id', $requests))
            ->orWhere(fn ($q) => $q->where('subject_type', 'RideConversation')->whereIn('subject_id', $chats))
            ->orWhere(fn ($q) => $q->where('subject_type', 'RideReview')->whereIn('subject_id', $reviews)));
    }

    /** @return array<string, int> */
    public function purge(): array
    {
        $counts = ['contexts' => 0, 'conversations' => 0, 'requests' => 0, 'cases' => 0, 'rides' => 0, 'audits' => 0];
        // Serialise hold changes and erasure: neither may silently overtake the other.
        DB::transaction(function () use (&$counts): void {
            DB::table('carpool_mutexes')->where('name', 'retention')->lockForUpdate()->firstOrFail();
            $counts['contexts'] = $this->unheldAudits()->whereNotNull('context')->where('context_expires_at', '<=', now())->update(['context' => null]);
            RideConversation::whereNull('purged_at')->with('rideRequest.offer')->chunkById(100, function ($rows) use (&$counts): void {
                foreach ($rows as $chat) {
                    if ($this->readable($chat) || $this->heldRequest($chat->rideRequest)) {
                        continue;
                    }
                    DB::table('chat_messages')->where('conversation_id', $chat->conversation_id)->delete();
                    $chat->update(['purged_at' => now(), 'read_only_at' => $chat->read_only_at ?? now()]);
                    $counts['conversations']++;
                }
            });
            RideRequest::whereNotNull('note')->with('offer')->chunkById(100, function ($rows) use (&$counts): void {
                foreach ($rows as $ride) {
                    $closed = $ride->closed_at ?? $ride->offer->closed_at ?? $ride->offer->departure_at->addHours(config()->integer('carpool.chat_hours'));
                    if ($closed->addDays(config()->integer('carpool.message_retention_days'))->isPast() && ! $this->heldRequest($ride)) {
                        $ride->update(['note' => null]);
                        $counts['requests']++;
                    }
                }
            });
            $cutoff = now()->subDays(config()->integer('carpool.history_retention_days'));
            Report::whereIn('id', CarpoolCase::where('closed_at', '<=', $cutoff)->where(fn ($q) => $q->whereNull('hold_until')->orWhere('hold_until', '<=', now()))->select('source_report_id'))->update(['note' => null, 'ip_address' => null, 'resolution_note' => null]);
            $counts['cases'] = CarpoolCase::where('closed_at', '<=', $cutoff)->where(fn ($q) => $q->whereNull('hold_until')->orWhere('hold_until', '<=', now()))->delete();
            RideOffer::whereIn('status', ['cancelled', 'completed'])->where('closed_at', '<=', $cutoff)
                ->whereNotIn('id', CarpoolCase::whereNotNull('ride_offer_id')->select('ride_offer_id'))->chunkById(100, function ($rows) use (&$counts): void {
                    foreach ($rows as $offer) {
                        $ids = $offer->requests()->pluck('id');
                        $chats = RideConversation::whereIn('ride_request_id', $ids)->get();
                        foreach ($chats as $chat) {
                            DB::table('chat_conversations')->where('id', $chat->conversation_id)->delete();
                            $chat->delete();
                        }
                        DB::table('ride_feedback')->whereIn('ride_request_id', $ids)->delete();
                        DB::table('ride_reviews')->whereIn('ride_request_id', $ids)->delete();
                        DB::table('ride_occupancies')->where('ride_offer_id', $offer->id)->delete();
                        $offer->requests()->delete();
                        $offer->delete();
                        $counts['rides']++;
                    }
                });
            $counts['audits'] = $this->unheldAudits()->where('created_at', '<=', $cutoff)->delete();
            DB::table('ride_searches')->where('active', false)->where('latest_at', '<=', $cutoff)->delete();
            DB::table('carpool_commands')->where('created_at', '<=', $cutoff)->delete();
            DB::table('notifications')->whereIn('data->category', ['social', 'carpool', 'chat'])->where('created_at', '<=', $cutoff)->delete();
            DB::table('community_delivery_outbox')->where('created_at', '<=', $cutoff)->delete();
            DB::table('community_notification_receipts')->whereNotIn('notification_id', DB::table('notifications')->select('id'))->delete();
        }, 5);

        return $counts;
    }
}
