<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enums\CommunityStatus;
use App\Enums\ProfileVisibility;
use App\Enums\SavedVisibility;
use App\Enums\VenueStatus;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityProfile;
use App\Models\SavedEvent;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\Venue;
use App\Notifications\CommunityNotification;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Overtrue\LaravelFollow\Followable;

final class Community
{
    public function __construct(private readonly CommunityAccess $access) {}

    public function requireVerified(User $user): void
    {
        abort_unless($user->isWhatsappVerified(), 403, __('community.verification_required'));
    }

    /** @param array<string, mixed> $data */
    public function profile(User $user, array $data): CommunityProfile
    {
        return DB::transaction(function () use ($user, $data): CommunityProfile {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->requireVerified($locked);
            $profile = $locked->communityProfile()->firstOrNew();
            $profile->fill(collect($data)->only(['handle', 'display_name', 'bio', 'city_id', 'visibility'])->all());
            $profile->indexable = $profile->visibility === ProfileVisibility::Public && ($data['indexable'] ?? false);
            $profile->save();
            if (array_key_exists('venue_ids', $data)) {
                $venues = Venue::query()->whereIn('id', $data['venue_ids'] ?? [])->where('status', VenueStatus::Approved)
                    ->whereIn('id', $locked->follows()->where('followable_type', 'venue')->select('followable_id'))->pluck('id');
                $profile->venues()->sync($venues);
            }

            return $profile;
        });
    }

    public function follow(User $user, User $target, bool $following): void
    {
        abort_if($user->id === $target->id, 422);
        DB::transaction(function () use ($user, $target, $following): void {
            $people = User::query()->whereIn('id', [$user->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $people->get($user->id);
            $recipient = $people->get($target->id);
            abort_unless($actor && $recipient && $actor->hasVerifiedEmail(), 403);
            if (! $following) {
                $actor->unfollow($recipient);

                return;
            }
            abort_unless($actor->community_suspended_at === null, 403);
            abort_unless($this->access->profiles($actor)->where('user_id', $recipient->id)->exists(), 404);
            if (! $actor->isFollowing($recipient)) {
                $actor->follow($recipient);
                $recipient->notify(new CommunityNotification('follow', route('community.followers')));
            }
        });
    }

    public function block(User $user, User $target, bool $blocked): void
    {
        abort_if($user->id === $target->id, 422);
        DB::transaction(function () use ($user, $target, $blocked): void {
            User::query()->whereIn('id', [$user->id, $target->id])->orderBy('id')->lockForUpdate()->get();
            if (! $blocked) {
                UserBlock::query()->where('user_id', $user->id)->where('blocked_user_id', $target->id)->delete();

                return;
            }
            UserBlock::query()->firstOrCreate(['user_id' => $user->id, 'blocked_user_id' => $target->id]);
            $user->unfollow($target);
            $target->unfollow($user);
        });
    }

    /**
     * Seguaci e blocchi di un utente, con un numero di query che non cresce con la pagina:
     * visibilità dei profili e nomi dei bloccati si leggono una volta sola.
     *
     * @return array{page: LengthAwarePaginator<int, Followable>, followers: list<array{user_id: int, display_name: string, handle: string|null}>, blocks: list<array{user_id: int, display_name: string}>}
     */
    public function followers(User $user, int $perPage = 30): array
    {
        /** @var LengthAwarePaginator<int, Followable> $page */
        $page = Followable::query()->where('followable_type', $user->getMorphClass())->where('followable_id', $user->id)->whereNotNull('accepted_at')
            ->with('follower.communityProfile')->orderByDesc('id')->paginate($perPage);
        $visible = $this->access->visibleProfileIds($user, $page->getCollection()->pluck('user_id'));
        $followers = $page->getCollection()->map(function (Followable $follow) use ($visible): array {
            $person = $follow->follower;
            $profile = $person instanceof User ? $person->communityProfile : null;

            return ['user_id' => (int) $follow->user_id, 'display_name' => $profile->display_name ?? __('community.member'),
                'handle' => $profile !== null && in_array($profile->id, $visible, true) ? $profile->handle : null];
        })->values()->all();
        $blockedIds = UserBlock::query()->where('user_id', $user->id)->orderBy('id')->pluck('blocked_user_id')->map(fn ($id) => (int) $id);
        // Come prima con User::find(): un account cancellato resta anonimo.
        $names = CommunityProfile::query()->whereIn('user_id', User::query()->whereIn('id', $blockedIds->all())->select('id'))->pluck('display_name', 'user_id');
        $blocks = $blockedIds->map(fn (int $id): array => ['user_id' => $id, 'display_name' => (string) ($names[$id] ?? __('community.member'))])->values()->all();

        return ['page' => $page, 'followers' => $followers, 'blocks' => $blocks];
    }

    /** @param array<string, mixed> $data */
    public function publication(User $user, int $occurrence, array $data): ?CommunityPost
    {
        return DB::transaction(function () use ($user, $occurrence, $data): ?CommunityPost {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $saved = SavedEvent::query()->where('user_id', $user->id)->where('occurrence_id', $occurrence)->lockForUpdate()->firstOrFail();
            if ($data['visibility'] === SavedVisibility::Private->value) {
                $saved->forceFill(['visibility' => SavedVisibility::Private])->save();
                CommunityPost::query()->where('saved_event_id', $saved->id)->delete();

                return null;
            }
            $this->requireVerified($locked);
            abort_if(DB::table('community_restrictions')->where('user_id', $user->id)->where('occurrence_id', $occurrence)->exists(), 403);
            if (! $locked->communityProfile()->exists()) {
                throw ValidationException::withMessages(['visibility' => __('community.profile_required')]);
            }
            $post = CommunityPost::query()->firstOrNew(['saved_event_id' => $saved->id]);
            $post->fill(['user_id' => $user->id, 'occurrence_id' => $occurrence, 'body' => $data['body'] ?? null, 'intent' => $data['intent'] ?? 'recommend']);
            if (! $post->exists) {
                $post->published_at = CarbonImmutable::now();
                $post->status = CommunityStatus::Published;
            }
            $saved->forceFill(['visibility' => SavedVisibility::Public])->save();
            $post->save();
            activity('community')->causedBy($user)->performedOn($post)->event('post_saved')->log('post_saved');

            return $post;
        });
    }

    public function comment(User $user, CommunityPost $post, string $body, ?int $parentId): CommunityComment
    {
        return DB::transaction(function () use ($user, $post, $body, $parentId): CommunityComment {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->requireVerified($locked);
            abort_unless($locked->communityProfile()->exists(), 403, __('community.profile_required'));
            abort_unless($this->access->canViewPost($locked, $post), 404);
            $parent = $parentId === null ? null : $this->access->comments($locked, $post)->whereNull('parent_id')->findOrFail($parentId);
            $comment = CommunityComment::query()->create(['community_post_id' => $post->id, 'user_id' => $locked->id, 'body' => $body,
                'parent_id' => $parent?->id, 'status' => CommunityStatus::Published]);
            foreach (array_unique(array_filter([$post->user_id, $parent?->user_id])) as $recipient) {
                if ($recipient !== $locked->id) {
                    $person = User::query()->find($recipient);
                    if ($person !== null && ! $this->access->blocked($locked, $person)) {
                        $person->notify(new CommunityNotification('comment', route('community.post', $post)));
                    }
                }
            }

            return $comment;
        });
    }

    public function deleteComment(User $user, CommunityComment $comment): void
    {
        abort_unless($comment->user_id === $user->id || $comment->post->user_id === $user->id || $user->isEditorialStaff(), 403);
        $comment->delete();
    }
}
