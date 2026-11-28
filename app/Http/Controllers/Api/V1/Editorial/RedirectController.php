<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Api\V1\Editorial\Concerns\ListsPlaces;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Settings\StoreRedirectRequest;
use App\Models\City;
use App\Models\Redirect;
use App\Services\Editorial\EditorialSettings;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Gli indirizzi cambiati (R-SY-13), come la risorsa del pannello:
 * `RedirectPolicy` (`pages.manage`, admin e super admin; il moderatore
 * riceve 403). Elenco con ricerca sui due indirizzi, ordinato dal passaggio
 * più recente, con passaggi e ultimo passaggio ordinabili; si aggiunge una
 * riga. Il pannello non ha «Modifica» né «Elimina» su questa tabella (la
 * policy li permetterebbe), e nemmeno l'app.
 *
 * La città non filtra: il pannello mostra le righe di tutte le città.
 */
final class RedirectController extends Controller
{
    use ListsPlaces;

    private const SORTS = ['hits', 'last_hit_at'];

    public function index(Request $request, EditorialSettings $settings): JsonResponse
    {
        $user = EditorialDesk::require()->user;
        Gate::forUser($user)->authorize('viewAny', Redirect::class);
        $q = $this->listSearch($request);
        [$column, $direction] = $this->sort($this->listSort($request));

        $page = Redirect::query()
            ->with('city:id,name')
            ->when($q !== '', function (Builder $query) use ($q): void {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner->where('from_path', 'like', $like)->orWhere('to_path', 'like', $like));
            })
            ->orderBy($column, $direction)->orderBy('id', 'desc')
            ->paginate($this->listPerPage($request), page: $this->listPage($request));

        $payload = $this->listPayload($page, $page->getCollection()->map(fn (Redirect $redirect): array => $settings->redirectRow($redirect))->values()->all());
        $payload['meta']['abilities'] = ['create' => Gate::forUser($user)->allows('create', Redirect::class)];

        return response()->json($payload);
    }

    /** Le scelte del modulo: tutte le città (anche non attive, come il pannello) e i due tipi di spostamento. */
    public function options(EditorialSettings $settings): JsonResponse
    {
        Gate::forUser(EditorialDesk::require()->user)->authorize('create', Redirect::class);

        return response()->json(['data' => [
            'cities' => City::query()->orderBy('name')->get(['id', 'name'])->map(fn (City $city): array => ['id' => $city->id, 'name' => $city->name])->values()->all(),
            'statuses' => [
                ['value' => 301, 'label' => $settings->redirectStatusLabel(301)],
                ['value' => 302, 'label' => $settings->redirectStatusLabel(302)],
            ],
            'default_status' => 301,
        ]]);
    }

    public function store(StoreRedirectRequest $request, EditorialSettings $settings): JsonResponse
    {
        Gate::forUser(EditorialDesk::require()->user)->authorize('create', Redirect::class);
        $data = $request->validated();
        $data['is_wildcard'] = (bool) ($data['is_wildcard'] ?? false);
        $redirect = Redirect::query()->create($data);

        return response()->json([
            'data' => $settings->redirectRow($redirect->load('city:id,name')->refresh()),
            'meta' => ['message' => __('editorial-settings.redirects.created')],
        ], 201);
    }

    /** @return array{0: string, 1: 'asc'|'desc'} */
    private function sort(string $sort): array
    {
        $column = ltrim($sort, '-');
        if (! in_array($column, self::SORTS, true)) {
            /* Come il pannello: dal passaggio più recente. */
            return ['last_hit_at', 'desc'];
        }

        return [$column, str_starts_with($sort, '-') ? 'desc' : 'asc'];
    }
}
