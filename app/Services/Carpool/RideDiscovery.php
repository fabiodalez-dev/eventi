<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\Enums\RideAccessibility;
use App\Enums\RideLeg;
use App\Enums\RideStatus;
use App\Models\EventOccurrence;
use App\Models\RideFeedback;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideSearch;
use App\Models\RideTemplate;
use App\Models\User;
use App\Models\UserBlock;
use App\Queries\CarpoolQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class RideDiscovery
{
    public function __construct(private CarpoolAccess $access, private CarpoolQuery $clock) {}

    /** @param array<string, mixed> $filters
     * @return Builder<RideOffer>
     */
    public function offers(User $user, EventOccurrence $date, array $filters): Builder
    {
        $this->access->requireEligible($user);
        abort_unless($this->clock->visible($date), 404);

        return RideOffer::where('occurrence_id', $date->id)->where('status', RideStatus::Open)->where('departure_at', '>', $this->clock->now($date))
            ->whereHas('driver', fn ($q) => $this->eligibleQuery($q))
            ->whereNotIn('driver_id', $this->blockedIds($user))
            ->whereRaw('capacity - (SELECT COALESCE(SUM(seats), 0) FROM ride_requests WHERE ride_offer_id = ride_offers.id AND status = ?) >= ?', ['accepted', max(1, (int) ($filters['seats'] ?? 1))])
            ->when($filters['leg'] ?? null, fn ($q, $leg) => $q->where('leg', $leg))
            ->when($filters['zone'] ?? null, fn ($q, $zone) => $q->where(fn ($q) => $q->where('zone', 'like', '%'.addcslashes($zone, '%_\\').'%')->orWhere('stops', 'like', '%'.addcslashes($zone, '%_\\').'%')))
            ->when(($filters['accessibility'] ?? 'not_specified') !== 'not_specified', fn ($q) => $q->where('accessibility', $filters['accessibility']))
            ->with(['driver.communityProfile', 'occurrence.event.city'])
            ->withSum(['requests as occupied_seats' => fn ($q) => $q->where('status', 'accepted')], 'seats')
            ->when(($filters['sort'] ?? 'departure') === 'recent', fn ($q) => $q->orderByDesc('id'), fn ($q) => $q->orderBy('departure_at')->orderBy('id'));
    }

    /** @return list<int> */
    public function blockedIds(User $user): array
    {
        return UserBlock::where('user_id', $user->id)->pluck('blocked_user_id')->merge(UserBlock::where('blocked_user_id', $user->id)->pluck('user_id'))->map(fn ($id) => (int) $id)->all();
    }

    /** @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     */
    public function eligibleQuery(Builder $query): void
    {
        $query->whereNotNull('email_verified_at')->whereIn('id', User::query()->withVerifiedContactOrExemption()->select('id'))
            ->whereNull('community_suspended_at')->whereNull('carpool_suspended_at')
            ->whereIn('id', DB::table('carpool_profiles')->whereNotNull('adult_declared_at')->select('user_id'));
    }

    public function matches(RideSearch $search, RideOffer $offer): bool
    {
        // I passaggi dimostrativi non diventano mai proposte né avvisi per chi cerca davvero.
        return $search->active && $search->user_id !== $offer->driver_id && $offer->status === RideStatus::Open && ! $offer->isDemo()
            && $search->occurrence_id === $offer->occurrence_id && $search->leg === $offer->leg
            && $offer->departure_at->betweenIncluded($search->earliest_at, $search->latest_at)
            && ($search->accessibility === RideAccessibility::NotSpecified || $search->accessibility === $offer->accessibility)
            && (blank($search->zone) || str_contains(mb_strtolower($offer->zone.' '.implode(' ', $offer->stops ?? [])), mb_strtolower($search->zone)))
            && app(CarpoolService::class)->available($offer) >= $search->seats
            && $search->user && $offer->driver && $this->access->eligible($search->user) && $this->access->eligible($offer->driver, false)
            && ! $this->access->blocked($search->user, $offer->driver) && $this->clock->operational($offer);
    }

    /** @param array<string, mixed> $data
     * @return array{entity: string, id: int}
     */
    public function execute(User $user, string $action, array $data): array
    {
        $key = $data['request_key'];
        unset($data['request_key']);
        $dateId = $data['occurrence_id'] ?? (isset($data['search_id']) ? RideSearch::findOrFail($data['search_id'])->occurrence_id : null);
        $id = app(CarpoolCommands::class)->run($user, $key, $action, $data, $dateId, function (User $actor) use ($data, $action): int {
            $this->access->requireEligible($actor, $action !== 'feedback');
            $audit = app(CarpoolAuditLog::class);
            if ($action === 'search') {
                $date = EventOccurrence::findOrFail($data['occurrence_id']);
                $leg = RideLeg::from($data['leg']);
                $start = $this->clock->departure($data['earliest_at'], $date);
                $end = $this->clock->departure($data['latest_at'], $date);
                abort_unless($start->lt($end) && $this->clock->allowed($date, $leg, $start) && $this->clock->allowed($date, $leg, $end), 422, __('carpool.errors.departure'));
                abort_if(RideSearch::where('user_id', $actor->id)->where('active', true)->where(fn ($q) => $q->where('occurrence_id', '!=', $date->id)->orWhere('leg', '!=', $leg))->count() >= 20, 422, __('carpool.errors.limit'));
                $this->freeSearch($actor, $date->id, $leg);
                $record = RideSearch::updateOrCreate(['user_id' => $actor->id, 'occurrence_id' => $date->id, 'leg' => $leg],
                    ['zone' => $data['zone'] ?? null, 'seats' => $data['seats'], 'earliest_at' => $start, 'latest_at' => $end,
                        'accessibility' => $data['accessibility'], 'is_public' => $data['is_public'], 'alerts_enabled' => $data['alerts_enabled'], 'active' => true]);
            } elseif ($action === 'toggle-search') {
                $record = RideSearch::where('user_id', $actor->id)->findOrFail($data['search_id']);
                if ($data['active']) {
                    abort_unless($this->clock->visible($record->occurrence) && $record->latest_at->isFuture(), 422, __('carpool.errors.closed'));
                    $this->freeSearch($actor, $record->occurrence_id, $record->leg);
                    abort_if(RideSearch::where('user_id', $actor->id)->whereKeyNot($record->id)->where('active', true)->count() >= 20, 422, __('carpool.errors.limit'));
                }
                $record->update(['active' => $data['active']]);
            } elseif ($action === 'suggest') {
                $record = RideSearch::findOrFail($data['search_id']);
                $offer = RideOffer::where('driver_id', $actor->id)->findOrFail($data['offer_id']);
                abort_unless($record->is_public && $this->matches($record, $offer), 404);
                if (! DB::table('ride_suggestions')->where('ride_search_id', $record->id)->where('ride_offer_id', $offer->id)->exists()) {
                    DB::table('ride_suggestions')->insert(['ride_search_id' => $record->id, 'ride_offer_id' => $offer->id, 'created_at' => now(), 'updated_at' => now()]);
                    app(CommunityNotices::class)->send($record->user, 'carpool', 'suggestion', 'offer', $offer->id, 'suggestion:'.$record->id.':'.$offer->id, searchId: $record->id);
                }
            } elseif ($action === 'template') {
                $offer = RideOffer::where('driver_id', $actor->id)->findOrFail($data['offer_id']);
                abort_if(RideTemplate::where('user_id', $actor->id)->count() >= 20, 422, __('carpool.errors.limit'));
                $record = RideTemplate::create(['user_id' => $actor->id, 'name' => $data['name'], 'settings' => $offer->only(['leg', 'zone', 'capacity', 'accessibility', 'accessibility_note', 'note', 'stops'])]);
            } elseif ($action === 'delete-template') {
                $record = RideTemplate::where('user_id', $actor->id)->findOrFail($data['template_id']);
                $record->delete();
            } elseif ($action === 'feedback') {
                $request = RideRequest::findOrFail($data['request_id']);
                abort_unless($this->access->participant($actor, $request) && $request->accepted_at !== null && ! $this->clock->future($request->offer), 403);
                $record = RideFeedback::firstOrCreate(['ride_request_id' => $request->id, 'user_id' => $actor->id], ['kind' => $data['kind'], 'body' => $data['body'] ?? null]);
            } else {
                abort(404);
            }
            $audit->record($actor, 'ride_'.$action, $record);

            return $record->id;
        });

        return ['entity' => 'discovery', 'id' => $id];
    }

    private function freeSearch(User $user, int $occurrenceId, RideLeg $leg): void
    {
        abort_if(DB::table('ride_occupancies')->where('user_id', $user->id)->where('occurrence_id', $occurrenceId)->where('leg', $leg->value)->exists(), 409, __('carpool.errors.occupied'));
    }
}
