<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Event;
use App\Models\EventComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class EventCommentQuery
{
    /** @return array<string, mixed> */
    public function listing(Event $event, ?User $user, int $page, ?int $targetId, ?int $replyPage): array
    {
        $query = EventComment::query()->where('event_id', $event->id)->visibleTo($user);
        $target = $targetId === null ? null : (clone $query)->findOrFail($targetId);
        $rootId = $target === null ? null : ($target->parent_id ?? $target->id);
        $explicitReplyPage = $replyPage !== null;
        $replyPage = max(1, $replyPage ?? 1);
        if ($target?->parent_id !== null && ! $explicitReplyPage) {
            $position = (clone $query)->where('parent_id', $rootId)->where('id', '<=', $target->id)->count();
            $replyPage = max(1, (int) ceil($position / 20));
        }
        $hydrate = static fn (Builder $q) => $q->with('user:id,name')
            ->withCount('reactions')
            ->with(['reactions' => fn ($r) => $r->where('user_id', $user->id ?? 0)->select(['id', 'event_comment_id', 'user_id', 'type'])]);
        $roots = $hydrate((clone $query)->topLevel())
            ->withCount(['replies' => fn ($q) => $q->visibleTo($user)])
            ->when($rootId !== null, fn ($q) => $q->whereKey($rootId))
            ->when($rootId === null, fn ($q) => $q->with(['replies' => fn ($replies) => $hydrate($replies->visibleTo($user)->getQuery())->orderBy('id')->limit(3)]))
            ->orderByDesc('id')
            ->paginate(10, page: $rootId === null ? max(1, $page) : 1);
        $repliesLastPage = 1;
        if ($rootId !== null) {
            abort_if($roots->isEmpty(), 404);
            $replies = $hydrate((clone $query)->where('parent_id', $rootId))->orderBy('id')
                ->paginate(20, page: $replyPage);
            $roots->first()->setRelation('replies', $replies->getCollection());
            $repliesLastPage = $replies->lastPage();

        }

        /*
         * Quanti commenti ha l'evento, non quanti ne ha questa pagina.
         *
         * In vista conversazione la query dei capostipiti è ristretta a uno
         * solo (`whereKey`), quindi `$roots->total()` vale 1: l'intestazione
         * diceva «Commenti (1)» anche su un evento che ne aveva cinquanta.
         * Il conto vero costa una query in più, e solo su quella vista.
         */
        $total = $rootId === null ? $roots->total() : (clone $query)->topLevel()->count();

        return [
            'comments' => $roots->getCollection(),
            'commentsPage' => $roots->currentPage(),
            'commentsLastPage' => $roots->lastPage(),
            'commentsTotal' => $total,
            'commentThread' => $rootId,
            'repliesPage' => $replyPage,
            'repliesLastPage' => $repliesLastPage,
        ];
    }
}
