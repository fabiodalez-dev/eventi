<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\Enums\CommunityStatus;
use App\Enums\Permission;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideReview;
use App\Models\User;
use App\Queries\CarpoolQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class RideReviews
{
    public function reader(?User $user, User $driver): bool
    {
        return $user !== null && app(CarpoolAccess::class)->contacts($user) && app(CarpoolAccess::class)->contacts($driver)
            && ! app(CarpoolAccess::class)->blocked($user, $driver);
    }

    public function eligibleTrip(User $user, RideRequest $ride): bool
    {
        $departure = $ride->offer->departure_at;

        return $user->id === $ride->user_id && app(CarpoolAccess::class)->eligible($user, false) && $ride->accepted_at !== null
            && ! app(CarpoolQuery::class)->future($ride->offer) && $departure->gt(now()->subDays(config()->integer('carpool.history_retention_days')))
            && ($ride->closed_at === null || $ride->closed_at->gte($departure))
            && ($ride->offer->closed_at === null || $ride->offer->closed_at->gte($departure));
    }

    /** @return Builder<RideReview> */
    public function published(User $driver): Builder
    {
        return RideReview::where('driver_id', $driver->id)->where('status', CommunityStatus::Published)->whereNotNull('rating')
            ->whereHas('user', fn ($q) => $q->whereNull('deleted_at')->whereNull('community_suspended_at')->whereNull('carpool_suspended_at'))
            ->whereIn('ride_request_id', RideRequest::whereNotNull('passenger_confirmed_at')->whereIn('ride_offer_id', RideOffer::where('departure_at', '>', now()->subDays(config()->integer('carpool.history_retention_days')))->select('id'))->select('id'));
    }

    /** @return array{count: int, average: ?float}|null */
    public function summary(?User $viewer, User $driver): ?array
    {
        if (! $this->reader($viewer, $driver)) {
            return null;
        }
        $row = $this->published($driver)->selectRaw('COUNT(*) AS total, AVG(rating) AS average')->first();

        return ['count' => (int) $row->getAttribute('total'), 'average' => $row->getAttribute('average') === null ? null : round((float) $row->getAttribute('average'), 1)];
    }

    /** @return array<string, mixed> */
    public function resource(RideReview $review): array
    {
        return ['id' => $review->id, 'rating' => $review->rating, 'body' => $review->body, 'revision' => $review->revision,
            'name' => $review->user?->communityProfile->display_name ?? $review->user->name ?? __('community.member'),
            'driver_id' => $review->driver_id, 'status' => $review->status->value,
            'confirmed_at' => $review->rideRequest->passenger_confirmed_at?->toIso8601String(),
            'created_at' => $review->created_at->toIso8601String(), 'updated_at' => $review->updated_at->toIso8601String()];
    }

    /** @param array<string, mixed> $data */
    public function change(User $user, RideRequest $ride, string $action, array $data): int
    {
        return app(CarpoolCommands::class)->run($user, $data['request_key'], 'review_'.$action, ['ride' => $ride->id, ...$data], $ride->offer->occurrence_id,
            function (User $actor) use ($ride, $action, $data): int {
                $ride = RideRequest::whereKey($ride->id)->lockForUpdate()->firstOrFail();
                abort_unless($ride->user_id === $actor->id, 403);
                $review = RideReview::withTrashed()->where('ride_request_id', $ride->id)->lockForUpdate()->first();
                if ($action === 'remove') {
                    abort_unless($review && ! $review->trashed() && $review->revision === (int) $data['revision'], 409, __('carpool.errors.changed'));
                    $review->update(['rating' => null, 'body' => null, 'revision' => $review->revision + 1]);
                    $review->delete();
                } else {
                    abort_unless($this->eligibleTrip($actor, $ride), 403, __('carpool.errors.review_trip'));
                    if ($action === 'confirm') {
                        if ($ride->passenger_confirmed_at === null) {
                            $ride->update(['passenger_confirmed_at' => now()]);
                        }
                    } else {
                        abort_unless($ride->passenger_confirmed_at !== null, 422, __('carpool.errors.review_confirm'));
                        abort_unless(($review->revision ?? 0) === (int) $data['revision'], 409, __('carpool.errors.changed'));
                        $review ??= new RideReview(['ride_request_id' => $ride->id, 'user_id' => $actor->id, 'driver_id' => $ride->offer->driver_id]);
                        $review->fill(['rating' => $data['rating'], 'body' => $data['body'] ?? null, 'status' => $review->moderated_at ? CommunityStatus::Hidden : CommunityStatus::Published,
                            'revision' => $review->exists ? $review->revision + 1 : 1, 'deleted_at' => null])->save();
                        if ($review->status === CommunityStatus::Published && ! app(CarpoolAccess::class)->blocked($actor, $ride->offer->driver)) {
                            app(CommunityNotices::class)->send($ride->offer->driver, 'carpool', 'review', 'driver', $ride->offer->driver_id, 'review:'.$review->id.':'.$review->revision);
                        }
                    }
                }
                app(CarpoolAuditLog::class)->record($actor, 'ride_review_'.$action, $ride, ['review_id' => $review?->id]);

                return $ride->id;
            });
    }

    public function moderate(User $admin, RideReview $review, bool $visible, string $reason): void
    {
        app(CommunitySafety::class)->requireStaff($admin, Permission::ManageCarpool);
        abort_if(mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 1000, 422);
        DB::transaction(function () use ($admin, $review, $visible, $reason): void {
            $review = RideReview::whereKey($review->id)->lockForUpdate()->firstOrFail();
            $review->update(['status' => $visible ? CommunityStatus::Published : CommunityStatus::Hidden, 'moderated_at' => $visible ? null : now(), 'revision' => $review->revision + 1]);
            app(CarpoolAuditLog::class)->record($admin, 'ride_review_moderated', $review, ['visible' => $visible, 'reason' => $reason]);
            app(CommunityNotices::class)->send($review->user, 'carpool', 'review_moderated', 'request', $review->ride_request_id, 'review-moderated:'.$review->id.':'.$review->revision);
        }, 5);
    }
}
