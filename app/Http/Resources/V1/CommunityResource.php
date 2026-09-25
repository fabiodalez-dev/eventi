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
    /**
     * @param  list<int>|null  $followingIds  utenti seguiti dal viewer, calcolati una volta per pagina
     * @return array<string, mixed>
     */
    public static function profile(CommunityProfile $profile, ?User $viewer, ?array $followingIds = null): array
    {
        $counts = app(CommunityAccess::class)->relationCounts($profile->user);

        return [
            'id' => $profile->id, 'user_id' => $profile->user_id, 'handle' => $profile->handle,
            'display_name' => $profile->display_name, 'bio' => $profile->bio, 'avatar_url' => $profile->avatarUrl() ?: null,
            'url' => route('community.profile', $profile->handle), 'verified' => $profile->user->isWhatsappVerified(),
            'city' => $profile->city?->name, 'city_id' => $profile->city_id,
            'visibility' => $profile->visibility->value, 'indexable' => $profile->indexable,
            'followers_count' => $counts['followers'],
            'following_count' => $counts['following'],
            'is_following' => $followingIds !== null ? in_array($profile->user_id, $followingIds, true) : ($viewer?->isFollowing($profile->user) ?? false),
            'is_own' => $viewer?->id === $profile->user_id,
        ];
    }

    /**
     * @param  list<int>|null  $followingIds
     * @return array<string, mixed>
     */
    public static function post(CommunityPost $post, ?User $viewer, ApiContext $context, ?array $followingIds = null): array
    {
        // L'autore è già caricato con i conteggi: il profilo lo riusa invece di ricaricarlo.
        $author = $post->user->communityProfile;
        $author->setRelation('user', $post->user);

        return ['id' => $post->id, 'body' => $post->body, 'intent' => $post->intent->value,
            'published_at' => $post->published_at->toIso8601String(), 'url' => route('community.post', $post),
            'author' => self::profile($author, $viewer, $followingIds),
            'occurrence' => OccurrenceResource::toArray($post->occurrence, $context),
            'is_own' => $post->user_id === $viewer?->id,
            'can_comment' => app(CommunityAccess::class)->state($viewer)['can_comment']];
    }

    /**
     * @param  list<int>|null  $visibleProfileIds  profili visibili al viewer, calcolati una volta per pagina
     * @return array<string, mixed>
     */
    public static function comment(CommunityComment $comment, ?User $viewer, ?array $visibleProfileIds = null): array
    {
        $profile = $comment->user->communityProfile;
        $visible = $profile !== null && ($viewer?->id === $comment->user_id || ($visibleProfileIds !== null
            ? in_array($profile->id, $visibleProfileIds, true)
            : app(CommunityAccess::class)->profiles($viewer)->whereKey($profile->id)->exists()));

        // Il nome di un profilo non visibile al viewer (solo membri, privato) non esce nemmeno nei commenti.
        return ['id' => $comment->id, 'parent_id' => $comment->parent_id, 'body' => $comment->body,
            'display_name' => $visible ? $profile->display_name : __('community.member'),
            'handle' => $visible ? $profile->handle : null,
            'created_at' => $comment->created_at->toIso8601String(),
            // Stessa policy che autorizza la cancellazione: il pulsante non promette ciò che il server rifiuta.
            'can_delete' => $viewer?->can('delete', $comment) ?? false];
    }
}
