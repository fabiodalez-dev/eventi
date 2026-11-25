<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Actions\Comments\ModerateComment;
use App\Enums\EventCommentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\ManagedCommentsQuery;
use App\Http\Requests\Api\V1\Management\ModerateCommentRequest;
use App\Models\EventComment;
use App\Models\User;
use App\Services\Management\ManagedFeedback;
use App\Support\Management\ManagementSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * I commenti agli eventi del locale o dell'organizzazione (W-CM-01…04), come
 * la pagina «Commenti agli eventi» dei pannelli: elenco con filtro di stato e
 * ricerca, «Nascondi» con nota facoltativa, «Rimetti in vista». Qui non si
 * cancella: è un potere della redazione e dell'autore (`EventCommentPolicy`).
 */
final class CommentModerationController extends Controller
{
    public function index(ManagedCommentsQuery $request, ManagedFeedback $feedback): JsonResponse
    {
        $user = $this->user($request);
        $status = $request->validated('status');
        $event = $request->validated('event');
        $page = $feedback->comments(
            ManagementSubject::require()->model,
            is_string($status) ? $status : null,
            is_string($request->validated('q')) ? trim($request->validated('q')) : null,
            $event === null ? null : (int) $event,
            (int) ($request->validated('page') ?? 1),
        );

        return response()->json([
            'data' => $page->getCollection()->map(fn (EventComment $comment): array => $feedback->comment($comment, $user))->values(),
            'meta' => ['next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null, 'total' => $page->total()],
        ]);
    }

    /** Nasconde un commento pubblicato, con la revisione letta e una nota facoltativa. */
    public function hide(ModerateCommentRequest $request, ModerateComment $moderation, ManagedFeedback $feedback): JsonResponse
    {
        $user = $this->user($request);
        $comment = $request->comment();
        $this->expectStatus($comment, EventCommentStatus::Published);
        $note = $request->validated('note');
        $moderation->nascondi($comment, $user, (int) $request->validated('revision'), is_string($note) && trim($note) !== '' ? trim($note) : null);

        return $this->result($comment, $user, $feedback, __('comments.moderation.hidden'));
    }

    /** Rimette in vista un commento nascosto (non quelli nascosti dalla redazione: lo decide la policy). */
    public function restore(ModerateCommentRequest $request, ModerateComment $moderation, ManagedFeedback $feedback): JsonResponse
    {
        $user = $this->user($request);
        $comment = $request->comment();
        $this->expectStatus($comment, EventCommentStatus::Hidden);
        $moderation->ripristina($comment, $user, (int) $request->validated('revision'));

        return $this->result($comment, $user, $feedback, __('comments.moderation.restored'));
    }

    /**
     * Il pannello offre «Nascondi» solo sui pubblicati e «Rimetti in vista»
     * solo sui nascosti. Un'app con un elenco vecchio riceve lo stesso errore
     * di un conflitto di revisione: qualcuno ha già deciso, va riletto.
     */
    private function expectStatus(EventComment $comment, EventCommentStatus $status): void
    {
        if ($comment->status !== $status) {
            throw ValidationException::withMessages(['revision' => __('comments.moderation.changed')]);
        }
    }

    private function result(EventComment $comment, User $user, ManagedFeedback $feedback, string $message): JsonResponse
    {
        $fresh = EventComment::query()->whereKey($comment->id)
            ->with(['event:id,title,slug,venue_id,organizer_id', 'event.organizer', 'user:id,name', 'moderator:id,name', 'moderator.roles'])
            ->withCount('reactions')->firstOrFail();

        return response()->json(['data' => $feedback->comment($fresh, $user), 'meta' => ['message' => $message]]);
    }

    private function user(ManagedCommentsQuery|ModerateCommentRequest $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
