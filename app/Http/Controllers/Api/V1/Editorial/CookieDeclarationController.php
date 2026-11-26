<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Enums\ConsentCategory;
use App\Http\Controllers\Api\V1\Editorial\Concerns\ListsPlaces;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Editorial\Settings\ReorderCookiesRequest;
use App\Http\Requests\Api\V1\Editorial\Settings\StoreCookieRequest;
use App\Models\CookieDeclaration;
use App\Services\Editorial\EditorialPermissions;
use App\Services\Editorial\EditorialSettings;
use App\Support\Editorial\EditorialDesk;
use App\Support\SecurityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Il registro dei cookie (R-SY-12), come la risorsa «Cookie» del pannello:
 * admin e super admin (`CookieDeclarationResource::canAccess`, la chiave
 * `system_manage`). Una scheda per finalità, ricerca su nome, fornitore e
 * scopo, ordine di `sort_order`; si aggiunge un cookie e si riordina la
 * scheda. Il pannello non ha «Modifica» né «Elimina» su questa tabella, e
 * nemmeno l'app.
 */
final class CookieDeclarationController extends Controller
{
    use ListsPlaces;

    public function index(Request $request, EditorialSettings $settings, EditorialPermissions $permissions): JsonResponse
    {
        $this->authorizeDesk($permissions);
        $categories = ConsentCategory::values();
        $tab = $this->listTab($request, $categories, $categories[0]);
        $q = $this->listSearch($request);

        $page = CookieDeclaration::query()
            ->where('category', $tab)
            ->when($q !== '', function (Builder $query) use ($q): void {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $query->where(fn (Builder $inner) => $inner->where('name', 'like', $like)->orWhere('provider', 'like', $like)->orWhere('purpose', 'like', $like));
            })
            ->orderBy('sort_order')->orderBy('id')
            ->paginate($this->listPerPage($request), page: $this->listPage($request));

        $counts = CookieDeclaration::query()->select('category', DB::raw('count(*) as total'))->groupBy('category')->pluck('total', 'category');
        $tabs = [];
        foreach ($categories as $category) {
            $tabs[$category] = (int) ($counts[$category] ?? 0);
        }

        $payload = $this->listPayload($page, $page->getCollection()->map(fn (CookieDeclaration $cookie): array => $settings->cookieRow($cookie))->values()->all(), $tabs);
        $payload['meta']['tab'] = $tab;
        $payload['meta']['categories'] = $settings->categories();

        return response()->json($payload);
    }

    /** «Aggiungi un cookie»: la finalità della scheda aperta arriva già scelta dall'app. */
    public function store(StoreCookieRequest $request, EditorialSettings $settings, EditorialPermissions $permissions): JsonResponse
    {
        $this->authorizeDesk($permissions);
        $cookie = CookieDeclaration::query()->create($request->validated());

        return response()->json([
            'data' => $settings->cookieRow($cookie->fresh() ?? $cookie),
            'meta' => ['message' => __('editorial-settings.cookies.created')],
        ], 201);
    }

    /**
     * Il nuovo ordine di una scheda, come il trascinamento del pannello: la
     * prima riga prende 1, la seconda 2, e così via.
     */
    public function reorder(ReorderCookiesRequest $request, EditorialSettings $settings, EditorialPermissions $permissions): JsonResponse
    {
        $this->authorizeDesk($permissions);
        /** @var list<int> $ids */
        $ids = array_map('intval', (array) $request->validated('ids'));
        $category = (string) $request->validated('category');

        DB::transaction(function () use ($ids, $category): void {
            foreach ($ids as $index => $id) {
                CookieDeclaration::query()->whereKey($id)->where('category', $category)->update(['sort_order' => $index + 1]);
            }
        });
        SecurityLog::configurazione('cookie_riordinati', ['oggetto' => 'cookie_declaration', 'categoria' => $category, 'dopo' => $ids]);

        $rows = CookieDeclaration::query()->where('category', $category)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (CookieDeclaration $cookie): array => $settings->cookieRow($cookie))->values()->all();

        return response()->json([
            'data' => $rows,
            'meta' => ['message' => __('editorial-settings.cookies.reordered')],
        ]);
    }

    private function authorizeDesk(EditorialPermissions $permissions): void
    {
        abort_unless($permissions->allows(EditorialDesk::require()->user, 'system_manage'), 403);
    }
}
