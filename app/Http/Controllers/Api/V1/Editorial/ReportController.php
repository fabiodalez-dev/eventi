<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Actions\Moderation\ResolveReport;
use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Moderation\ReportListRequest;
use App\Http\Requests\Api\V1\Editorial\Moderation\ResolveReportRequest;
use App\Models\Report;
use App\Services\Editorial\ModerationDesk;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Le segnalazioni (R-MD-01…04), come la risorsa «Segnalazioni» del pannello:
 * elenco con i filtri di stato, motivo e «aperte»; l'esito (stato e nota,
 * firmato con chi decide e quando); l'eliminazione, che è definitiva come
 * nel pannello (il modello non ha il cestino) e per questo chiede la
 * password recente. Per i soggetti della community la riga dice
 * `subject.community`: l'app offre «Modera il soggetto», che nel pannello
 * apre la pagina Community.
 */
final class ReportController extends Controller
{
    /** Elenco: tutta la redazione (`reports.view`), tutte le città. */
    public function index(ReportListRequest $request, ModerationDesk $moderation): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        Gate::forUser($user)->authorize('viewAny', Report::class);

        $page = $moderation->reports([
            'status' => $request->filterValue('status'),
            'reason' => $request->filterValue('reason'),
            'open' => $request->filterFlag('open'),
        ], $request->sortField(), $request->perPage(), $request->pageNumber());

        return response()->json([
            'data' => $page->getCollection()->map(fn (Report $report): array => $moderation->reportRow($report, $user))->values(),
            'meta' => ModerationDesk::meta($page),
        ]);
    }

    /** Una segnalazione, con l'esito e le azioni permesse. */
    public function show(int $report, ModerationDesk $moderation): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $found = $moderation->report($report);
        Gate::forUser($user)->authorize('view', $found);

        return response()->json(['data' => $moderation->reportRow($found, $user)]);
    }

    /** L'esito: `status` e `resolution_note` (`reports.resolve`). Chi e quando li scrive il server. */
    public function resolve(ResolveReportRequest $request, int $report, ModerationDesk $moderation, ResolveReport $resolve): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $found = $moderation->report($report);
        Gate::forUser($user)->authorize('update', $found);
        $note = $request->validated('resolution_note');
        $resolve->handle($found, $user, ReportStatus::from((string) $request->validated('status')), is_string($note) ? $note : null);

        return response()->json([
            'data' => $moderation->reportRow($moderation->report($found->id), $user),
            'meta' => ['message' => __('pannelli-redazione.segnalazioni.saved')],
        ]);
    }

    /** Elimina la segnalazione (`reports.resolve`), come «Elimina» nel modulo del pannello. Pericolosa: password recente. */
    public function destroy(int $report, ModerationDesk $moderation): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $found = $moderation->report($report);
        Gate::forUser($user)->authorize('delete', $found);
        $found->delete();

        return response()->json(['data' => ['id' => $report, 'deleted' => true], 'meta' => ['message' => __('pannelli-redazione.segnalazioni.deleted')]]);
    }
}
