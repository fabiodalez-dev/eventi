<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Actions\MergeTagsAction;
use App\Http\Controllers\Api\V1\Editorial\Concerns\ListsPlaces;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\Editorial\Catalog\CatalogCategories;
use App\Services\Editorial\Catalog\CatalogCities;
use App\Services\Editorial\Catalog\CatalogEventFeatures;
use App\Services\Editorial\Catalog\CatalogOverview;
use App\Services\Editorial\Catalog\CatalogPages;
use App\Services\Editorial\Catalog\CatalogSection;
use App\Services\Editorial\Catalog\CatalogTags;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Il catalogo della redazione (R-CT-01…07): categorie, tag, caratteristiche
 * degli eventi, città e pagine, come le cinque risorse del gruppo
 * «Contenuti» del pannello. Le rotte di elenco, modulo, creazione e
 * modifica sono le stesse per le cinque voci: la voce arriva dalla rotta
 * (`section`) e decide il servizio. Eliminare resta solo sul sito
 * (decisione 4: è definitivo, nessuna delle cinque voci va nel cestino):
 * la riga dice `abilities.delete_on_web` e porta `web_path`.
 *
 * Ogni azione rifà la domanda del pannello alla policy del modello:
 * l'elenco chiede `viewAny` (categorie, tag e città a tutta la redazione;
 * caratteristiche e pagine ad admin e super admin), il modulo e la modifica
 * `update`, la creazione `create`. Il moderatore
 * legge gli elenchi e non apre i moduli, come nel pannello. La città di
 * lavoro non filtra: il pannello mostra tutto il catalogo.
 */
final class CatalogController extends Controller
{
    use ListsPlaces;

    /** Le voci che la persona vede, con il conteggio e se può crearne. */
    public function overview(CatalogOverview $overview): JsonResponse
    {
        $user = EditorialDesk::require()->user;

        return response()->json(['data' => ['sections' => $overview->sections($user)]]);
    }

    /** Le scelte dei moduli (categorie, città, gruppi e icone, fusi, lingue). Per chi crea o modifica qualcosa del catalogo. */
    public function choices(CatalogOverview $overview): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        abort_unless(collect($overview->sections($user))->contains(fn (array $section): bool => $section['can_create']), 403);

        return response()->json(['data' => $overview->choices()]);
    }

    /**
     * L'elenco: `q` (le colonne cercabili del pannello), `filter[…]` (i
     * filtri sì/no, `1` o `0`), `sort` (le colonne ordinabili, `-` per il
     * verso opposto), `page`, `per_page`. Ogni riga ha le sue `abilities`.
     */
    public function index(Request $request, CatalogOverview $overview): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $section = $this->section($request);
        abort_unless($overview->allows($user, $section), 403);
        $service = $this->service($section);
        $page = $service->paginate(
            $this->listFilters($request, $service->filters()),
            $this->listSearch($request),
            $this->listSort($request),
            $this->listPerPage($request),
            $this->listPage($request),
        );

        return response()->json($this->listPayload(
            $page,
            array_values(array_map(fn ($record): array => $service->row($record, $user), $page->items())),
        ));
    }

    /** Il modulo completo, per chi può modificare (nel pannello la pagina di modifica). */
    public function show(Request $request): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $service = $this->service($this->section($request));
        $record = $service->find($this->id($request));
        Gate::forUser($user)->authorize('update', $record);

        return response()->json(['data' => $service->form($record, $user)]);
    }

    /** Una voce nuova. Risponde con il modulo salvato. */
    public function store(Request $request): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $service = $this->service($this->section($request));
        Gate::forUser($user)->authorize('create', $service->model());
        $record = $service->create($request->all(), $user);

        return response()->json(['data' => $service->form($record, $user), 'meta' => ['message' => __('editorial-catalog.created')]], 201);
    }

    /**
     * Salva il modulo. Le pagine chiedono anche la `revision` letta (409 se
     * nel frattempo sono cambiate); le città la password recente (rotta
     * pericolosa, anche per crearne una).
     */
    public function update(Request $request): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $service = $this->service($this->section($request));
        $record = $service->find($this->id($request));
        Gate::forUser($user)->authorize('update', $record);
        $saved = $service instanceof CatalogPages && $record instanceof Page
            ? $service->updateRevised($record, $request->all(), $user)
            : $service->update($record, $request->all(), $user);

        return response()->json(['data' => $service->form($saved, $user), 'meta' => ['message' => __('editorial-catalog.saved')]]);
    }

    /**
     * Unisce il tag `absorbed_id` in questo (R-CT-04, password recente): gli
     * eventi passano qui, il nome diventa un sinonimo, il doppione sparisce.
     */
    public function mergeTag(Request $request, CatalogTags $tags): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        $keep = $tags->tag($this->id($request));
        Gate::forUser($user)->authorize('delete', $keep);
        $data = $request->validate([
            'absorbed_id' => ['required', 'integer', Rule::exists('tags', 'id'), Rule::notIn([$keep->getKey()])],
        ], [], ['absorbed_id' => __('editorial-catalog.merge_absorbed')]);
        $absorbed = $tags->tag((int) $data['absorbed_id']);
        /* Il tag assorbito viene eliminato: chi unisce deve poter eliminare anche lui. */
        MergeTagsAction::authorize($user, $keep, $absorbed);
        $moved = $tags->merge($keep, $absorbed);

        return response()->json([
            'data' => $tags->form($keep->refresh(), $user),
            'meta' => ['message' => __('admin.notifications.merged', ['count' => $moved]), 'moved' => $moved],
        ]);
    }

    /** L'anteprima del corpo Markdown di una pagina, con la conversione del sito. */
    public function previewPage(Request $request): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        Gate::forUser($user)->authorize('viewAny', Page::class);
        $data = $request->validate(['body' => ['present', 'nullable', 'string', 'max:'.Page::BODY_MAX]], [], ['body' => __('admin.fields.body')]);

        return response()->json(['data' => ['html' => CatalogPages::preview((string) ($data['body'] ?? ''))]]);
    }

    /** La voce del catalogo, scritta nella rotta (`->defaults('section', …)`). */
    private function section(Request $request): string
    {
        $section = $request->route('section');

        return is_string($section) ? $section : '';
    }

    /** L'id numerico della voce (`whereNumber`). */
    private function id(Request $request): int
    {
        return (int) $request->route('id');
    }

    private function service(string $section): CatalogSection
    {
        return match ($section) {
            'categories' => app(CatalogCategories::class),
            'tags' => app(CatalogTags::class),
            'event_features' => app(CatalogEventFeatures::class),
            'cities' => app(CatalogCities::class),
            'pages' => app(CatalogPages::class),
            default => abort(404),
        };
    }
}
