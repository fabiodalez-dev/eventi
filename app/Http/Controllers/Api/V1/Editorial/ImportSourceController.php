<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\System\ImportRunListRequest;
use App\Http\Requests\Api\V1\Editorial\System\ImportSourceListRequest;
use App\Http\Requests\Api\V1\Editorial\System\SaveImportSourceRequest;
use App\Models\ImportRun;
use App\Models\ImportSource;
use App\Services\Editorial\EditorialImportSources;
use App\Services\Editorial\ModerationDesk;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Le sorgenti di import (R-SY-01…04), come la risorsa «Sorgenti di import»
 * del pannello: elenco con i filtri tipo, attiva e «Import falliti»; modulo
 * (città, locale, tipo, categoria predefinita, indirizzo, mappatura, attiva);
 * anteprima; «Esegui adesso»; eliminazione; storico delle esecuzioni.
 *
 * Solo admin e super admin (`import_sources.manage`, `ImportSourcePolicy`):
 * il moderatore riceve 403. Le credenziali non si leggono e non si scrivono
 * dall'app (decisione 6): la riga dice solo se ci sono, e `web_path` è la
 * scheda del pannello dove si cambiano. L'eliminazione è definitiva (il
 * modello non ha il cestino; con la sorgente va il suo storico, gli eventi
 * già importati restano) e chiede la password recente.
 */
final class ImportSourceController extends Controller
{
    public function index(ImportSourceListRequest $request, EditorialImportSources $sources): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        Gate::forUser($user)->authorize('viewAny', ImportSource::class);

        $page = $sources->sources([
            'type' => $request->filterValue('type'),
            'active' => $request->activeFilter(),
            'failed' => $request->filterFlag('failed'),
        ], $request->sortField(), $request->perPage(), $request->pageNumber());

        return response()->json([
            'data' => $page->getCollection()->map(fn (ImportSource $source): array => $sources->row($source, $user))->values(),
            'meta' => ModerationDesk::meta($page),
        ]);
    }

    /** Le scelte del modulo: tipi, città, categorie, valori predefiniti, tetti. */
    public function form(EditorialImportSources $sources): JsonResponse
    {
        Gate::forUser(EditorialDesk::require()->user)->authorize('viewAny', ImportSource::class);

        return response()->json(['data' => $sources->form()]);
    }

    /** I locali per il campo «Locale», cercati per nome (`q`), al più 20. */
    public function venues(Request $request, EditorialImportSources $sources): JsonResponse
    {
        Gate::forUser(EditorialDesk::require()->user)->authorize('viewAny', ImportSource::class);
        $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $q = trim((string) $request->query('q', ''));

        return response()->json(['data' => $sources->venues($q === '' ? null : $q)]);
    }

    public function show(int $source, EditorialImportSources $sources): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $found = $sources->source($source);
        Gate::forUser($user)->authorize('view', $found);

        return response()->json(['data' => $sources->row($found, $user)]);
    }

    /** Crea una sorgente (`ImportSourcePolicy::create`, nella richiesta). */
    public function store(SaveImportSourceRequest $request, EditorialImportSources $sources): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $saved = $sources->save(null, $request->validated());

        return response()->json([
            'data' => $sources->row($saved, $user),
            'meta' => ['message' => __('admin.import.saved')],
        ], 201);
    }

    /** Modifica, anche parziale (`is_active` da solo è «attiva/disattiva»). */
    public function update(SaveImportSourceRequest $request, int $source, EditorialImportSources $sources): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $saved = $sources->save($sources->source($source), $request->validated());

        return response()->json([
            'data' => $sources->row($saved, $user),
            'meta' => ['message' => __('admin.import.saved')],
        ]);
    }

    /** Elimina la sorgente e il suo storico. Pericolosa: password recente. */
    public function destroy(int $source, EditorialImportSources $sources): JsonResponse
    {
        $found = $sources->source($source);
        Gate::forUser(EditorialDesk::require()->user)->authorize('delete', $found);
        $found->delete();

        return response()->json(['data' => ['id' => $source, 'deleted' => true], 'meta' => ['message' => __('admin.import.deleted')]]);
    }

    /**
     * L'anteprima: le prime date che entrerebbero, nell'ora della città,
     * senza scrivere niente. Un calendario che non si legge non è un errore
     * della richiesta: il motivo torna in `error`, come nella finestra.
     */
    public function preview(int $source, EditorialImportSources $sources): JsonResponse
    {
        $found = $this->runnable($source, $sources);

        return response()->json(['data' => [...$sources->preview($found), 'timezone' => $found->city->timezone]]);
    }

    /** «Esegui adesso»: accoda la lettura, come il pulsante del pannello. */
    public function run(int $source, EditorialImportSources $sources): JsonResponse
    {
        $found = $this->runnable($source, $sources);
        $sources->run($found);

        return response()->json(['data' => ['id' => $found->id, 'queued' => true], 'meta' => ['message' => __('admin.notifications.import_queued')]], 202);
    }

    /** Lo storico delle esecuzioni, dalla più recente; solo lettura. */
    public function runs(ImportRunListRequest $request, int $source, EditorialImportSources $sources): JsonResponse
    {
        $found = $sources->source($source);
        Gate::forUser(EditorialDesk::require()->user)->authorize('view', $found);
        $page = $sources->runs($found, $request->filterValue('status'), $request->sortField(), $request->perPage(), $request->pageNumber());

        return response()->json([
            'data' => $page->getCollection()->map(fn (ImportRun $run): array => $sources->runRow($run))->values(),
            'meta' => ModerationDesk::meta($page),
        ]);
    }

    /** Anteprima ed esecuzione: `ImportSourcePolicy::run` e un tipo che ha un lettore (il pannello nasconde i pulsanti agli altri). */
    private function runnable(int $source, EditorialImportSources $sources): ImportSource
    {
        $found = $sources->source($source);
        Gate::forUser(EditorialDesk::require()->user)->authorize('run', $found);

        if (! $sources->runnable($found)) {
            throw new ApiException(ApiErrorCode::ValidationFailed, fields: ['type' => [__('admin.import.not_runnable')]]);
        }

        return $found;
    }
}
