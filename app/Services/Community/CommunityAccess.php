<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enums\CommunityStatus;
use App\Enums\ProfileVisibility;
use App\Models\City;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityProfile;
use App\Models\User;
use App\Models\UserBlock;
use App\Queries\EventOccurrenceQuery;
use Illuminate\Database\Eloquent\Builder;

final class CommunityAccess
{
    /** @return Builder<User> */
    public function eligibleUsers(): Builder
    {
        return User::query()->whereNotNull('email_verified_at')->whereNotNull('whatsapp_verified_at')
            ->whereNotNull('whatsapp_phone_hash')->whereNull('community_suspended_at');
    }

    /** @return Builder<CommunityProfile> */
    public function profiles(?User $viewer): Builder
    {
        return CommunityProfile::query()->whereIn('user_id', $this->eligibleUsers()->select('id'))
            ->whereIn('visibility', $viewer?->hasVerifiedEmail() ? [ProfileVisibility::Public->value, ProfileVisibility::Members->value] : [ProfileVisibility::Public->value])
            ->when($viewer !== null, fn ($query) => $this->excludeBlocked($query, $viewer))
            ->with(['user', 'city', 'media']);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function excludeBlocked(Builder $query, User $viewer): void
    {
        $query->whereNotIn('user_id', UserBlock::query()->where('user_id', $viewer->id)->select('blocked_user_id'))
            ->whereNotIn('user_id', UserBlock::query()->where('blocked_user_id', $viewer->id)->select('user_id'));
    }

    public function blocked(User $first, User $second): bool
    {
        return UserBlock::query()->where(fn ($q) => $q->where('user_id', $first->id)->where('blocked_user_id', $second->id))
            ->orWhere(fn ($q) => $q->where('user_id', $second->id)->where('blocked_user_id', $first->id))->exists();
    }

    /** @return Builder<CommunityPost> */
    public function posts(?User $viewer, City $city, bool $upcoming = false): Builder
    {
        $dates = EventOccurrenceQuery::archiveFor($city);
        if ($upcoming) {
            $dates->ended(false);
        }

        return CommunityPost::query()->where('status', CommunityStatus::Published)
            ->whereHas('savedEvent', fn ($q) => $q->where('visibility', 'public'))
            ->whereIn('user_id', $this->profiles($viewer)->select('user_id'))
            ->whereIn('occurrence_id', $dates->identifiersQuery())
            ->with(['occurrence' => fn ($q) => $q->withCount('interestedUsers as interested_count'), 'user.communityProfile.media', 'occurrence.event.city', 'occurrence.event.venue', 'occurrence.venue', 'occurrence.event.category', 'occurrence.event.media', 'occurrence.event.tags', 'savedEvent']);
    }

    public function canViewPost(?User $viewer, CommunityPost $post): bool
    {
        $city = $post->occurrence?->event?->city;

        return $city !== null && $this->posts($viewer, $city)->whereKey($post->id)->exists();
    }

    /** @return Builder<CommunityComment> */
    public function comments(?User $viewer, CommunityPost $post): Builder
    {
        return CommunityComment::query()->where('community_post_id', $post->id)->where('status', CommunityStatus::Published)
            ->whereIn('user_id', $this->eligibleUsers()->select('id'))
            ->when($viewer !== null, fn ($query) => $this->excludeBlocked($query, $viewer))
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhereHas('parent', fn ($p) => $p->where('status', CommunityStatus::Published)->whereIn('user_id', $this->eligibleUsers()->select('id'))
                ->when($viewer !== null, fn ($parent) => $this->excludeBlocked($parent, $viewer))))
            ->with(['user.communityProfile.media']);
    }
}
