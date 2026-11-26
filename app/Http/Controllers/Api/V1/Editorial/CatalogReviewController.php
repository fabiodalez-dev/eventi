<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Moderation\ModerateReviewRequest;
use App\Http\Requests\Api\V1\Editorial\Moderation\ReviewListRequest;
use App\Models\CatalogReview;
use App\Services\Editorial\ModerationDesk;
use App\Services\Reviews\CatalogReviews;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Le recensioni di locali e organizzatori (R-MD-05…07), come la risorsa
 * «Recensioni» del pannello: solo admin e super admin (`CatalogReviewPolicy`;
 * il moderatore riceve 403 come nel pannello, dove la voce non c'è). Approva
 * o rifiuta con nota e revisione (`CatalogReviews::moderate`, la stessa del
 * pannello, che avvisa l'autore e scrive nel registro dei passaggi), togli
 * la risposta del gestore (`CatalogReviews::reply` con `null`).
 */
final class CatalogReviewController extends Controller
{
    public function index(ReviewListRequest $request, ModerationDesk $moderation): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        Gate::forUser($user)->authorize('viewAny', CatalogReview::class);

        $page = $moderation->reviews([
            'type' => $request->filterValue('type'),
            'status' => $request->filterValue('status'),
        ], $request->search(), $request->sortField(), $request->perPage(), $request->pageNumber());

        return response()->json([
            'data' => $page->getCollection()->map(fn (CatalogReview $review): array => $moderation->reviewRow($review, $user))->values(),
            'meta' => ModerationDesk::meta($page),
        ]);
    }

    /**
     * Approva (`status=approved`, nota facoltativa) o rifiuta
     * (`status=rejected`, nota obbligatoria), con la `revision` letta: se nel
     * frattempo la recensione è cambiata, 422 su `revision`.
     */
    public function moderate(ModerateReviewRequest $request, int $review, ModerationDesk $moderation, CatalogReviews $reviews): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $found = $moderation->review($review);
        Gate::forUser($user)->authorize('update', $found);
        $status = (string) $request->validated('status');
        $note = $request->validated('note');
        $reviews->moderate($found, $user, $status, (int) $request->validated('revision'), is_string($note) ? $note : null);

        return response()->json([
            'data' => $moderation->reviewRow($moderation->review($found->id), $user),
            'meta' => ['message' => __($status === 'approved' ? 'admin.notifications.approved' : 'admin.notifications.rejected')],
        ]);
    }

    /** Toglie la risposta pubblica del locale o dell'organizzatore. Senza risposta non c'è niente da togliere né da registrare. */
    public function removeReply(int $review, ModerationDesk $moderation, CatalogReviews $reviews): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $found = $moderation->review($review);
        Gate::forUser($user)->authorize('removeReply', $found);
        if (filled($found->owner_reply)) {
            $reviews->reply($found, $user, null);
        }

        return response()->json([
            'data' => $moderation->reviewRow($moderation->review($found->id), $user),
            'meta' => ['message' => __('reviews.reply.removed')],
        ]);
    }
}
