<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Community\CaseOperationRequest;
use App\Http\Requests\Api\V1\Editorial\Community\DeskListRequest;
use App\Http\Requests\Api\V1\Editorial\Community\ReasonRequest;
use App\Http\Requests\Api\V1\Editorial\Community\ReplyCaseRequest;
use App\Http\Requests\Api\V1\Editorial\Community\RestrictUserRequest;
use App\Http\Requests\Api\V1\Editorial\Community\ToggleRequest;
use App\Models\CarpoolCase;
use App\Models\RideOffer;
use App\Models\RideReview;
use App\Models\User;
use App\Services\Carpool\CommunitySafety;
use App\Services\Carpool\RideReviews;
use App\Services\Editorial\CarpoolDesk;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;

/**
 * La pagina «Passaggi in auto» del pannello (R-CP-01…10, R-CP-12): le sette
 * sezioni, il caso aperto con la conversazione e le sue azioni, e le azioni
 * delle righe. Ogni azione chiede il motivo e passa dalla stessa chiamata
 * del pannello (`CommunitySafety::manage`, `freezeChat`, `restrict`,
 * `cancelRide`, `retryDelivery`, `inspect`; `RideReviews::moderate`), che
 * ricontrolla permesso, rango e revisione e scrive nel registro dei
 * passaggi.
 *
 * Come il pannello: l'ingresso chiede `community_cases.manage`; passaggi,
 * persone, recensioni, feedback e consegne anche `carpool.manage`; la
 * conservazione delle evidenze e la loro lettura solo admin e super admin.
 * Conservare o rilasciare e leggere le evidenze chiedono la password
 * recente (nel pannello la password si scrive nella finestra). Esportarle
 * resta sul sito (decisione 8, R-CP-11).
 */
final class CarpoolController extends Controller
{
    public function index(DeskListRequest $request, string $section, CarpoolDesk $desk, CommunitySafety $safety): JsonResponse
    {
        $staff = $this->staff();
        if (CarpoolDesk::permission($section) === Permission::ManageCarpool) {
            $safety->requireStaff($staff, Permission::ManageCarpool);
        }
        $page = $desk->query($section, $request->search(), $request->status())->paginate($request->perPage(), page: $request->pageNumber());
        $items = $page->getCollection();
        if ($section === 'users' && $items instanceof EloquentCollection) {
            // Il rango decide «Sospendi»: i ruoli in una query sola.
            $items->loadMissing('roles');
        }

        return response()->json([
            'data' => $items->map(fn (mixed $item): array => $desk->row($section, $item, $staff))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Il caso aperto: testo, riferimenti, conversazione e azioni. */
    public function show(int $case, CarpoolDesk $desk): JsonResponse
    {
        $staff = $this->staff();

        return response()->json(['data' => $desk->caseDetail($desk->case($case), $staff)]);
    }

    /** Assegna a sé, aggiungi una nota interna, risolvi, riapri: con la revisione letta. */
    public function manage(CaseOperationRequest $request, int $case, CarpoolDesk $desk, CommunitySafety $safety): JsonResponse
    {
        $staff = $this->staff();
        $safety->manage($staff, CarpoolCase::findOrFail($case), (string) $request->validated('op'), $request->reason(), ['revision' => (int) $request->validated('revision')]);

        return $this->caseDone($desk, $case, $staff);
    }

    /** Risponde a una persona del caso: il caso passa in attesa di risposta e la persona riceve un avviso. */
    public function reply(ReplyCaseRequest $request, int $case, CarpoolDesk $desk, CommunitySafety $safety): JsonResponse
    {
        $staff = $this->staff();
        $safety->manage($staff, CarpoolCase::findOrFail($case), 'reply', trim((string) $request->validated('message')), [
            'revision' => (int) $request->validated('revision'),
            'recipient_id' => (int) $request->validated('recipient_id'),
        ]);

        return $this->caseDone($desk, $case, $staff);
    }

    /** Preserva le evidenze per 90 giorni o rilascia la preservazione: admin e super admin, password recente. */
    public function hold(CaseOperationRequest $request, int $case, CarpoolDesk $desk, CommunitySafety $safety): JsonResponse
    {
        $staff = $this->staff();
        $safety->manage($staff, CarpoolCase::findOrFail($case), (string) $request->validated('op'), $request->reason(), ['revision' => (int) $request->validated('revision')]);

        return $this->caseDone($desk, $case, $staff);
    }

    /** Oscura (`freeze: true`) o riapre la chat del passaggio del caso. */
    public function freeze(ToggleRequest $request, int $case, CarpoolDesk $desk, CommunitySafety $safety): JsonResponse
    {
        $staff = $this->staff();
        $safety->freezeChat($staff, CarpoolCase::findOrFail($case), $request->flag('freeze'), $request->reason());

        return $this->caseDone($desk, $case, $staff);
    }

    /**
     * Le evidenze del caso (R-CP-12): i messaggi della chat finché si possono
     * leggere, la recensione segnalata, il registro (a chi lo può leggere),
     * con l'impronta `sha256`. Ogni lettura resta nel registro dei passaggi
     * (`evidence_view`) con il motivo. Admin e super admin, password recente.
     */
    public function evidence(ReasonRequest $request, int $case, CommunitySafety $safety): JsonResponse
    {
        $staff = $this->staff();

        return response()->json(['data' => $safety->inspect($staff, CarpoolCase::findOrFail($case), $request->reason())]);
    }

    /** Annulla un passaggio in bozza, aperto o chiuso, e avvisa le persone coinvolte. */
    public function cancelRide(ReasonRequest $request, int $offer, CarpoolDesk $desk, CommunitySafety $safety): JsonResponse
    {
        $staff = $this->staff();
        $safety->requireStaff($staff, Permission::ManageCarpool);
        $safety->cancelRide($staff, RideOffer::findOrFail($offer), $request->reason());
        $fresh = RideOffer::with(['driver.communityProfile', 'occurrence.event'])->withCount('requests')->findOrFail($offer);

        return $this->done($desk->rideRow($fresh, $staff));
    }

    /** Nasconde (`visible: false`) o ripristina una recensione di passaggio; l'autore riceve un avviso. */
    public function moderateReview(ToggleRequest $request, int $review, CarpoolDesk $desk, RideReviews $reviews, CommunitySafety $safety): JsonResponse
    {
        $staff = $this->staff();
        $safety->requireStaff($staff, Permission::ManageCarpool);
        $reviews->moderate($staff, RideReview::findOrFail($review), $request->flag('visible'), $request->reason());

        return $this->done($desk->reviewRow(RideReview::with(['user', 'driver', 'rideRequest'])->findOrFail($review), $staff));
    }

    /** Sospende una persona in un ambito (social, passaggi, tutta la community). Pericolosa. */
    public function suspendUser(RestrictUserRequest $request, int $user, CarpoolDesk $desk, CommunitySafety $safety): JsonResponse
    {
        return $this->restriction($request, $user, true, $desk, $safety);
    }

    /** Ripristina una persona in un ambito. */
    public function restoreUser(RestrictUserRequest $request, int $user, CarpoolDesk $desk, CommunitySafety $safety): JsonResponse
    {
        return $this->restriction($request, $user, false, $desk, $safety);
    }

    /** Rimette in coda una consegna fallita (dopo otto tentativi). */
    public function retryDelivery(ReasonRequest $request, int $delivery, CommunitySafety $safety): JsonResponse
    {
        $staff = $this->staff();
        $safety->requireStaff($staff, Permission::ManageCarpool);
        $safety->retryDelivery($staff, $delivery, $request->reason());

        return $this->done(['kind' => 'delivery', 'id' => $delivery, 'retried' => true]);
    }

    private function restriction(RestrictUserRequest $request, int $user, bool $suspend, CarpoolDesk $desk, CommunitySafety $safety): JsonResponse
    {
        $staff = $this->staff();
        $safety->restrict($staff, User::findOrFail($user), (string) $request->validated('scope'), $suspend, $request->reason());

        return $this->done($desk->userRow(User::with(['communityProfile', 'roles'])->findOrFail($user), $staff));
    }

    private function caseDone(CarpoolDesk $desk, int $case, User $staff): JsonResponse
    {
        return $this->done($desk->caseDetail($desk->case($case), $staff));
    }

    /** @param  array<string, mixed>  $row */
    private function done(array $row): JsonResponse
    {
        return response()->json(['data' => $row, 'meta' => ['message' => __('admin.carpool_page.done')]]);
    }

    /** Chi entra nella pagina: la redazione con `community_cases.manage`, come `Carpool::canAccess`. */
    private function staff(): User
    {
        $user = EditorialDesk::require()->user;
        app(CommunitySafety::class)->requireStaff($user, Permission::ManageCommunityCases);

        return $user;
    }
}
