<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\Enums\RideRequestStatus;
use App\Enums\RideStatus;
use App\Models\EventOccurrence;
use App\Models\RideConversation;
use App\Models\RideOffer;
use App\Models\RideSearch;
use App\Models\User;
use App\Queries\CarpoolQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class CarpoolLifecycle
{
    public function __construct(private CarpoolAccess $access, private CarpoolQuery $clock, private CarpoolService $rides) {}

    public function reconcileUser(int $userId): void
    {
        if (! Schema::hasTable('ride_offers')) {
            return;
        }
        $ids = RideOffer::where('driver_id', $userId)->orWhereHas('requests', fn ($q) => $q->where('user_id', $userId))->pluck('occurrence_id')->unique();
        foreach ($ids as $id) {
            $this->reconcileDate((int) $id);
        }
        $user = User::find($userId);
        if (! $user || ! $this->access->eligible($user, false)) {
            RideSearch::where('user_id', $userId)->update(['active' => false]);
        }
    }

    public function reconcileDate(int $dateId): void
    {
        if (! Schema::hasTable('ride_offers')) {
            return;
        }
        DB::transaction(function () use ($dateId): void {
            // L'evento può essere stato cestinato: senza withTrashed la relazione
            // resterebbe vuota e ogni calcolo sull'orario della città fallirebbe.
            $date = EventOccurrence::withTrashed()->with(['event' => fn ($query) => $query->withTrashed()])
                ->whereKey($dateId)->lockForUpdate()->first();
            if (! $date) {
                return;
            }
            foreach (RideOffer::where('occurrence_id', $dateId)->whereIn('status', [RideStatus::Open, RideStatus::Closed])->lockForUpdate()->get() as $offer) {
                $future = $this->clock->future($offer);
                if ($future && (! $this->clock->visible($date) || $this->clock->changed($offer) || ! $offer->driver || ! $this->access->eligible($offer->driver, false))) {
                    $this->rides->cancelOffer($offer, null, 'requirements_or_event_changed');

                    continue;
                }
                foreach ($offer->requests()->whereIn('status', [RideRequestStatus::Pending, RideRequestStatus::Accepted])->lockForUpdate()->get() as $request) {
                    if (! $future && $request->status === RideRequestStatus::Pending) {
                        $this->rides->finish($request, RideRequestStatus::Expired, 'departure_reached', null);
                    } elseif ($future && (! $request->user || ! $this->access->eligible($request->user, false) || $this->access->blocked($request->user, $offer->driver))) {
                        $this->rides->finish($request, RideRequestStatus::Cancelled, 'requirements_or_block_changed', null);
                    }
                }
                if ($this->clock->now($date)->gte($offer->departure_at->addHours(config()->integer('carpool.chat_hours')))) {
                    $offer->update(['status' => RideStatus::Completed, 'active_key' => null, 'closed_at' => now()]);
                    DB::table('ride_occupancies')->where('ride_offer_id', $offer->id)->delete();
                    RideConversation::whereIn('ride_request_id', $offer->requests()->select('id'))->whereNull('read_only_at')->update(['read_only_at' => now()]);
                }
            }
        }, 5);
    }

    public function tick(): void
    {
        RideOffer::whereIn('status', [RideStatus::Open, RideStatus::Closed])->select('occurrence_id')->distinct()->orderBy('occurrence_id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    // Una data guasta non deve fermare le altre né gli avvisi che seguono.
                    try {
                        $this->reconcileDate($row->occurrence_id);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            }, 'occurrence_id');
        RideSearch::where('latest_at', '<=', now())->update(['active' => false]);
        RideSearch::where('active', true)->where('alerts_enabled', true)->with('user')->chunkById(100, function ($searches): void {
            foreach ($searches as $search) {
                foreach (RideOffer::where('occurrence_id', $search->occurrence_id)->where('leg', $search->leg)->where('status', RideStatus::Open)->get() as $offer) {
                    if (app(RideDiscovery::class)->matches($search, $offer)) {
                        DB::transaction(fn () => app(CommunityNotices::class)->send($search->user, 'carpool', 'match', 'offer', $offer->id, 'match:'.$search->id.':'.$offer->id, searchId: $search->id));
                    }
                }
            }
        });
        RideOffer::whereIn('status', [RideStatus::Open, RideStatus::Closed])->where('departure_at', '>', now())->where('departure_at', '<=', now()->addDay())
            ->with(['driver', 'requests.user'])->chunkById(100, function ($offers): void {
                foreach ($offers as $offer) {
                    $hours = $offer->departure_at->lte(now()->addHours(2)) ? 2 : 24;
                    foreach ($offer->requests->where('status', RideRequestStatus::Accepted) as $request) {
                        // Do not backfill a reminder whose threshold preceded confirmation.
                        if ($request->accepted_at->lte($offer->departure_at->subHours($hours))) {
                            foreach ([$request->user, $offer->driver] as $user) {
                                if ($user) {
                                    DB::transaction(fn () => app(CommunityNotices::class)->send($user, 'carpool', 'reminder', 'request', $request->id, 'reminder:'.$request->id.':'.$hours));
                                }
                            }
                        }
                    }
                    if ($offer->driver && $offer->requests->where('status', RideRequestStatus::Pending)->where('created_at', '<=', now()->subDay())->isNotEmpty()) {
                        DB::transaction(fn () => app(CommunityNotices::class)->send($offer->driver, 'carpool', 'pending', 'offer', $offer->id, 'pending:'.$offer->id.':'.now()->format('Y-m-d')));
                    }
                }
            });
    }
}
