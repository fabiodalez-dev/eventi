<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\ReceivedReviewRequest;
use App\Models\CatalogReview;
use App\Models\User;
use App\Services\Management\ManagedFeedback;
use App\Services\Reviews\CatalogReviews;
use App\Support\Management\ManagementSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le recensioni ricevute dal locale o dall'organizzazione (W-RV-01…03), come
 * la pagina «Recensioni ricevute» dei pannelli: solo le pubblicate, la
 * risposta pubblica da scrivere, cambiare o togliere. In più la segnalazione
 * alla redazione, la stessa che la scheda pubblica offre a chi partecipa alla
 * community (`CatalogReviews::report`, stesso limite di invio).
 *
 * Approvare o rifiutare resta della redazione: il gestore ha un interesse
 * diretto nel giudizio.
 */
final class ReceivedReviewController extends Controller
{
    public function index(Request $request, ManagedFeedback $feedback): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $page = $feedback->reviews(ManagementSubject::require()->model, (int) ($validated['page'] ?? 1));
        $can = $feedback->reviewPermissions($page->getCollection(), $user);

        return response()->json([
            'data' => $page->getCollection()->map(fn (CatalogReview $review): array => $feedback->review($review, $can))->values(),
            'meta' => ['next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null, 'total' => $page->total(), 'can_report' => $can['report']],
        ]);
    }

    /** Scrive o cambia la risposta pubblica (2–2000 caratteri). Solo la prima avvisa chi ha scritto la recensione. */
    public function reply(ReceivedReviewRequest $request, CatalogReviews $reviews, ManagedFeedback $feedback): JsonResponse
    {
        $user = $this->user($request);
        $reviews->reply($request->review(), $user, (string) $request->validated('reply'));

        return $this->result($request->review(), $user, $feedback, __('reviews.reply.saved'));
    }

    /** Toglie la risposta. Senza risposta non c'è niente da togliere, e niente da scrivere nel registro. */
    public function removeReply(ReceivedReviewRequest $request, CatalogReviews $reviews, ManagedFeedback $feedback): JsonResponse
    {
        $user = $this->user($request);
        if (filled($request->review()->owner_reply)) {
            $reviews->reply($request->review(), $user, null);
        }

        return $this->result($request->review(), $user, $feedback, __('reviews.reply.removed'));
    }

    /** Segnala la recensione alla redazione. Una seconda segnalazione aperta della stessa persona non crea un'altra pratica. */
    public function report(ReceivedReviewRequest $request, CatalogReviews $reviews): JsonResponse
    {
        $case = $reviews->report($this->user($request), $request->review(), (string) $request->validated('body'));

        return response()->json(['data' => ['case_id' => $case->id, 'message' => __('reviews.reported')]]);
    }

    private function result(CatalogReview $review, User $user, ManagedFeedback $feedback, string $message): JsonResponse
    {
        $fresh = CatalogReview::query()->whereKey($review->id)->with(['ratings', 'user.communityProfile', 'reviewable'])->firstOrFail();

        return response()->json(['data' => $feedback->review($fresh, $feedback->reviewPermissions([$fresh], $user)), 'meta' => ['message' => $message]]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
