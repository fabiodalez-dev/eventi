<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enums\CommunityStatus;
use App\Enums\ProfileVisibility;
use App\Enums\SavedVisibility;
use App\Models\City;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityProfile;
use App\Models\EventOccurrence;
use App\Models\SavedEvent;
use App\Models\User;
use App\Models\UserBlock;
use App\Queries\EventOccurrenceQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

final class CommunityAccess
{
    /** @return Builder<User> */
    public function eligibleUsers(): Builder
    {
        return User::query()->whereNotNull('email_verified_at')->whereNotNull('whatsapp_verified_at')
            ->whereNotNull('whatsapp_phone_hash')->whereNull('community_suspended_at')->whereNull('social_suspended_at');
    }

    /**
     * $ignoreBlocks serve solo alle segnalazioni: chi blocca (o è bloccato) deve poter
     * segnalare comunque. Idoneità e visibilità restano: non si segnala ciò che non si vede.
     *
     * @return Builder<CommunityProfile>
     */
    public function profiles(?User $viewer, bool $ignoreBlocks = false): Builder
    {
        return CommunityProfile::query()->whereIn('user_id', $this->eligibleUsers()->select('id'))
            ->whereIn('visibility', $viewer?->hasVerifiedEmail() ? [ProfileVisibility::Public->value, ProfileVisibility::Members->value] : [ProfileVisibility::Public->value])
            ->when($viewer !== null && ! $ignoreBlocks, fn ($query) => $this->excludeBlocked($query, $viewer))
            ->with(['user' => $this->withRelationCounts(...), 'city', 'media']);
    }

    /**
     * Chi ha dichiarato in pubblico che va a questa data.
     *
     * La visibilità si ricalcola a ogni lettura, come per i post: un profilo
     * che diventa privato, un account che perde la verifica o un blocco
     * tolgono la persona dall'elenco senza che nessun dato cambi.
     *
     * @return Builder<User>
     */
    public function attendees(EventOccurrence $occurrence, ?User $viewer): Builder
    {
        return User::query()
            ->whereIn('id', $this->profiles($viewer)->select('user_id'))
            ->whereIn('id', SavedEvent::query()->where('occurrence_id', $occurrence->getKey())
                ->where('visibility', SavedVisibility::Public->value)->select('user_id'))
            ->with('communityProfile.media')
            ->orderBy('id');
    }

    /**
     * Seguaci che contano in pubblico: email confermata, non sospesi, non cancellati.
     * Non serve WhatsApp: seguire è aperto a ogni account confermato.
     *
     * @return Builder<User>
     */
    private function countableFollowers(): Builder
    {
        return User::query()->whereNotNull('email_verified_at')->whereNull('community_suspended_at');
    }

    /**
     * Conteggi di seguaci e seguiti precaricati sull'autore, così una pagina di profili
     * o di post non fa due query per elemento.
     *
     * @param  Relation<*, *, *>  $query
     */
    public function withRelationCounts(Relation $query): void
    {
        $query->withCount([
            'followables as community_followers_count' => fn (Builder $q) => $this->followersOf($q),
            'followings as community_followings_count' => fn (Builder $q) => $this->followingsOf($q),
        ]);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function followersOf(Builder $query): Builder
    {
        return $query->whereNotNull('accepted_at')->whereIn('user_id', $this->countableFollowers()->select('id'));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function followingsOf(Builder $query): Builder
    {
        return $query->where('followable_type', (new User)->getMorphClass())->whereNotNull('accepted_at')
            ->whereIn('followable_id', $this->eligibleUsers()->select('id'));
    }

    /** @return array{followers: int, following: int} */
    public function relationCounts(User $user): array
    {
        $followers = $user->getAttribute('community_followers_count');
        $following = $user->getAttribute('community_followings_count');

        return [
            'followers' => $followers !== null ? (int) $followers : $this->followersOf($user->followables()->getQuery())->count(),
            'following' => $following !== null ? (int) $following : $this->followingsOf($user->followings()->getQuery())->count(),
        ];
    }

    /**
     * Fra gli utenti indicati, quelli che il viewer segue: una query per pagina.
     *
     * @param  iterable<int|string>  $userIds
     * @return list<int>
     */
    public function followingIds(?User $viewer, iterable $userIds): array
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($viewer === null || $ids->isEmpty()) {
            return [];
        }

        return $viewer->followings()->where('followable_type', (new User)->getMorphClass())->whereNotNull('accepted_at')
            ->whereIn('followable_id', $ids->all())->pluck('followable_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Profili visibili al viewer fra gli autori indicati: una query per pagina di commenti o seguaci.
     *
     * @param  iterable<int|string>  $userIds
     * @return list<int>
     */
    public function visibleProfileIds(?User $viewer, iterable $userIds): array
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return $this->profiles($viewer)->whereIn('user_id', $ids->all())->withoutEagerLoads()->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * @template TModel of Model
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

    /**
     * Post visibili al viewer. $upcoming: true solo date non concluse, false solo concluse,
     * null tutte (permalink: un post resta raggiungibile anche dopo l'evento).
     * $ignoreBlocks: vedi profiles().
     *
     * @return Builder<CommunityPost>
     */
    public function posts(?User $viewer, City $city, ?bool $upcoming = true, bool $ignoreBlocks = false): Builder
    {
        $dates = EventOccurrenceQuery::archiveFor($city);
        if ($upcoming !== null) {
            $dates->ended(! $upcoming);
        }

        return CommunityPost::query()->where('status', CommunityStatus::Published)
            ->whereHas('savedEvent', fn ($q) => $q->where('visibility', SavedVisibility::Public->value))
            // Il proprietario vede i propri post anche con profilo privato, finché resta idoneo.
            ->where(fn ($q) => $q->whereIn('user_id', $this->profiles($viewer, $ignoreBlocks)->select('user_id'))
                ->when($viewer !== null, fn ($q) => $q->orWhere(fn ($own) => $own->where('user_id', $viewer->id)->whereIn('user_id', $this->eligibleUsers()->select('id')))))
            ->whereIn('occurrence_id', $dates->identifiersQuery())
            ->with(['occurrence' => fn ($q) => $q->withCount('interestedUsers as interested_count'), 'user' => $this->withRelationCounts(...), 'user.communityProfile.media', 'user.communityProfile.city', 'occurrence.event.city', 'occurrence.event.venue', 'occurrence.venue', 'occurrence.event.category', 'occurrence.event.media', 'occurrence.event.tags', 'savedEvent']);
    }

    public function canViewPost(?User $viewer, CommunityPost $post, bool $ignoreBlocks = false): bool
    {
        $city = $post->occurrence?->event?->city;

        return $city !== null && $this->posts($viewer, $city, null, $ignoreBlocks)->whereKey($post->id)->exists();
    }

    /**
     * $ignoreBlocks: vedi profiles().
     *
     * @return Builder<CommunityComment>
     */
    public function comments(?User $viewer, CommunityPost $post, bool $ignoreBlocks = false): Builder
    {
        $blocks = $viewer !== null && ! $ignoreBlocks;

        return CommunityComment::query()->where('community_post_id', $post->id)->where('status', CommunityStatus::Published)
            ->whereIn('user_id', $this->eligibleUsers()->select('id'))
            ->when($blocks, fn ($query) => $this->excludeBlocked($query, $viewer))
            ->where(fn ($q) => $q->whereNull('parent_id')->orWhereHas('parent', fn ($p) => $p->where('status', CommunityStatus::Published)->whereIn('user_id', $this->eligibleUsers()->select('id'))
                ->when($blocks, fn ($parent) => $this->excludeBlocked($parent, $viewer))))
            ->with(['user.communityProfile.media']);
    }
}
