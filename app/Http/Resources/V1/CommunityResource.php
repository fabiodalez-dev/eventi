<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityProfile;
use App\Models\User;
use App\Services\Community\CommunityAccess;
use App\Support\Api\ApiContext;

final class CommunityResource
{
    /** @return array<string, mixed> */
    public static function profile(CommunityProfile $profile, ?User $viewer): array
    {
        return [
            'id' => $profile->id, 'user_id' => $profile->user_id, 'handle' => $profile->handle,
            'display_name' => $profile->display_name, 'bio' => $profile->bio, 'avatar_url' => $profile->avatarUrl() ?: null,
            'url' => route('community.profile', $profile->handle), 'verified' => $profile->user->isWhatsappVerified(),
            'city' => $profile->city?->name, 'city_id' => $profile->city_id,
            'visibility' => $profile->visibility->value, 'indexable' => $profile->indexable,
            'followers_count' => $profile->user->followers()->whereNotNull('accepted_at')->count(),
            'following_count' => $profile->user->followings()->whereNotNull('accepted_at')->count(),
            'is_following' => $viewer?->isFollowing($profile->user) ?? false, 'is_own' => $viewer?->id === $profile->user_id,
        ];
    }

    /** @return array<string, mixed> */
    public static function post(CommunityPost $post, ?User $viewer, ApiContext $context): array
    {
        return ['id' => $post->id, 'body' => $post->body, 'intent' => $post->intent->value,
            'published_at' => $post->published_at->toIso8601String(), 'url' => route('community.post', $post),
            'author' => self::profile($post->user->communityProfile, $viewer),
            'occurrence' => OccurrenceResource::toArray($post->occurrence, $context),
            'is_own' => $post->user_id === $viewer?->id,
            'can_comment' => $viewer?->isWhatsappVerified() && $viewer->communityProfile !== null];
    }

    /** @return array<string, mixed> */
    public static function comment(CommunityComment $comment, ?User $viewer): array
    {
        $profile = $comment->user->communityProfile;
        $visible = $profile !== null && app(CommunityAccess::class)->profiles($viewer)->whereKey($profile->id)->exists();

        return ['id' => $comment->id, 'parent_id' => $comment->parent_id, 'body' => $comment->body,
            'display_name' => $profile->display_name ?? __('community.member'),
            'handle' => $visible ? $profile->handle : null,
            'created_at' => $comment->created_at->toIso8601String(),
            'can_delete' => $viewer !== null && ($comment->user_id === $viewer->id || $comment->post->user_id === $viewer->id || $viewer->isEditorialStaff())];
    }
}
