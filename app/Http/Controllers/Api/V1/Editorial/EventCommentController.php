<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Actions\Comments\ModerateComment;
use App\Actions\Moderation\DeleteModeratedContent;
use App\Enums\EventCommentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Moderation\CommentListRequest;
use App\Http\Requests\Api\V1\Editorial\Moderation\ModerateEventCommentRequest;
use App\Models\EventComment;
use App\Services\Editorial\ModerationDesk;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * I commenti agli eventi (R-MD-08…12), come la risorsa «Commenti agli
 * eventi» del pannello: elenco con filtro di stato e ricerca, «Dettagli»,
 * «Nascondi» con nota, «Ripristina», «Elimina». Nascondere e ripristinare
 * passano da `ModerateComment` con la revisione letta; eliminare è di admin
 * e super admin (`EventCommentPolicy::delete`), fa sparire anche le risposte
 * e chiede la password recente.
 */
final class EventCommentController extends Controller
{
    public function index(CommentListRequest $request, ModerationDesk $moderation): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        Gate::forUser($user)->authorize('viewAny', EventComment::class);

        $page = $moderation->comments(['status' => $request->filterValue('status')], $request->search(), $request->sortField(), $request->perPage(), $request->pageNumber());

        return response()->json([
            'data' => $page->getCollection()->map(fn (EventComment $comment): array => $moderation->commentRow($comment, $user))->values(),
            'meta' => ModerationDesk::meta($page),
        ]);
    }

    /** «Dettagli»: il pannello lo mostra a chi può nascondere. */
    public function show(int $comment, ModerationDesk $moderation): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $found = $moderation->comment($comment);
        Gate::forUser($user)->authorize('hide', $found);

        return response()->json(['data' => $moderation->commentRow($found, $user)]);
    }

    /** Nasconde un commento pubblicato, con la revisione letta e una nota facoltativa (fino a 1000 caratteri). */
    public function hide(ModerateEventCommentRequest $request, int $comment, ModerationDesk $moderation, ModerateComment $moderate): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $found = $moderation->comment($comment);
        Gate::forUser($user)->authorize('hide', $found);
        self::expectStatus($found, EventCommentStatus::Published);
        $note = $request->validated('note');
        $moderate->nascondi($found, $user, (int) $request->validated('revision'), is_string($note) && trim($note) !== '' ? trim($note) : null);

        return response()->json(['data' => $moderation->commentRow($moderation->comment($found->id), $user), 'meta' => ['message' => __('comments.moderation.hidden')]]);
    }

    /** Rimette in vista un commento nascosto. */
    public function restore(ModerateEventCommentRequest $request, int $comment, ModerationDesk $moderation, ModerateComment $moderate): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $found = $moderation->comment($comment);
        Gate::forUser($user)->authorize('restore', $found);
        self::expectStatus($found, EventCommentStatus::Hidden);
        $moderate->ripristina($found, $user, (int) $request->validated('revision'));

        return response()->json(['data' => $moderation->commentRow($moderation->comment($found->id), $user), 'meta' => ['message' => __('comments.moderation.restored')]]);
    }

    /** Elimina il commento e le sue risposte, come «Elimina» del pannello, con la riga nel registro di sicurezza. Pericolosa: password recente. */
    public function destroy(int $comment, ModerationDesk $moderation, DeleteModeratedContent $delete): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $found = $moderation->comment($comment);
        Gate::forUser($user)->authorize('delete', $found);
        $delete->comment($found, $user);

        return response()->json(['data' => ['id' => $comment, 'deleted' => true], 'meta' => ['message' => __('comments.deleted')]]);
    }

    /**
     * Il pannello offre «Nascondi» solo sui pubblicati e «Ripristina» solo sui
     * nascosti. Un elenco vecchio riceve lo stesso errore di un conflitto di
     * revisione: qualcuno ha già deciso, va riletto.
     */
    private static function expectStatus(EventComment $comment, EventCommentStatus $status): void
    {
        if ($comment->status !== $status) {
            throw ValidationException::withMessages(['revision' => __('comments.moderation.changed')]);
        }
    }
}
