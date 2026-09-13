<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Enums\VenueReviewStatus;
use App\Enums\VenueStatus;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class VenueReviews
{
    /** @return array<string, mixed> */
    public function listing(Venue $venue, ?User $user, int $page = 1): array
    {
        $query = VenueReview::query()->where('venue_id', $venue->id)->approved();
        $count = (clone $query)->count();
        $average = $count > 0 ? round((float) (clone $query)->avg('rating'), 1) : null;
        $list = $query->with('user')->orderByDesc('moderated_at')->orderByDesc('id')->paginate(10, page: $page);
        $own = $user === null ? null : VenueReview::query()->where('venue_id', $venue->id)->where('user_id', $user->id)->first();

        return ['count' => $count, 'average' => $average, 'can_review' => $venue->status === VenueStatus::Approved,
            'reviews' => $list->map(static fn (VenueReview $review): array => [
                'id' => $review->id, 'author' => Str::before($review->user->name, ' '),
                'rating' => $review->rating, 'body' => $review->body, 'date' => $review->created_at?->format('Y-m-d'),
            ])->all(), 'page' => $list->currentPage(), 'last_page' => $list->lastPage(),
            'my_review' => $own === null ? null : array_merge($own->only(['rating', 'body', 'moderation_note']), ['status' => $own->status->value])];
    }

    public function submit(Venue $venue, User $user, int $rating, string $body): VenueReview
    {
        return DB::transaction(function () use ($venue, $user, $rating, $body): VenueReview {
            Venue::query()->whereKey($venue->id)->lockForUpdate()->firstOrFail();
            $review = VenueReview::query()->where('venue_id', $venue->id)->where('user_id', $user->id)->lockForUpdate()->first() ?? new VenueReview;
            $review->forceFill(['venue_id' => $venue->id, 'user_id' => $user->id, 'rating' => $rating,
                'body' => $body, 'status' => VenueReviewStatus::Pending, 'revision' => ($review->revision ?? 0) + 1,
                'moderated_by' => null, 'moderated_at' => null, 'moderation_note' => null])->save();

            return $review;
        });
    }

    public function moderate(VenueReview $review, User $admin, string $status, int $revision, ?string $note): void
    {
        Gate::forUser($admin)->authorize('update', $review);
        $decision = VenueReviewStatus::tryFrom($status);
        abort_unless(in_array($decision, [VenueReviewStatus::Approved, VenueReviewStatus::Rejected], true), 422);
        DB::transaction(function () use ($review, $admin, $decision, $revision, $note): void {
            $locked = VenueReview::query()->lockForUpdate()->findOrFail($review->id);
            if ($locked->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => __('reviews.changed')]);
            }
            $locked->forceFill(['status' => $decision, 'moderated_by' => $admin->id,
                'moderated_at' => now(), 'moderation_note' => $note, 'revision' => $locked->revision + 1])->save();
        });
    }
}
