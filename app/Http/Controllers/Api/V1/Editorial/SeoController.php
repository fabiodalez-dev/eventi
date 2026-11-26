<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Editorial;

use App\Http\Controllers\Api\V1\Editorial\Concerns\ListsPlaces;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\User;
use App\Services\Editorial\EditorialPermissions;
use App\Services\Seo\SearchConsoleVerification;
use App\Services\Seo\SeoAudit;
use App\Support\Editorial\EditorialDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «SEO e qualità del catalogo» (R-ST-04, R-ST-05), la pagina
 * `SeoOverview` del pannello:
 *
 * - `show`: il modulo di Search Console (città e codice salvato), i passi
 *   per collegarla e gli strumenti esterni, con i testi del pannello;
 * - `audit`: il controllo degli ultimi duecento eventi pubblici, venti per
 *   pagina, con le schede «Da sistemare» (predefinita) e «Tutti»;
 * - `verification`: salva il codice di verifica di una città.
 *
 * Come il pannello, la pagina guarda tutte le città (il controllo non
 * dipende dalla città di lavoro) ed è di admin e super admin
 * (`SeoOverview::canAccess`, `analytics_view`): il moderatore riceve 403.
 * Il salvataggio non scrive nel registro, come il pannello.
 */
final class SeoController extends Controller
{
    use ListsPlaces;

    public function __construct(
        private readonly SeoAudit $audit,
        private readonly SearchConsoleVerification $verification,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeSeo($request);
        $cities = City::query()->orderBy('id')->get();

        return response()->json(['data' => [
            'title' => __('seo.audit.title'),
            'search_console' => [
                'title' => __('seo.search_console.title'),
                'help' => __('seo.search_console.help'),
                'city_label' => __('seo.search_console.city'),
                'code_label' => __('seo.search_console.code'),
                'code_help' => __('seo.search_console.code_help'),
                'save_label' => __('seo.search_console.save'),
                'max' => SearchConsoleVerification::MAX,
                'pattern' => trim(SearchConsoleVerification::PATTERN, '/'),
                // La città che il pannello mostra aprendo la pagina: la prima.
                'default_city_id' => $cities->first()?->id,
                'cities' => $cities->map(fn (City $city): array => $this->city($city))->values()->all(),
            ],
            'steps' => [
                'heading' => __('admin.seo_page.steps_heading'),
                'items' => [
                    ['text' => __('seo.search_console.step1'), 'code' => url('/')],
                    ['text' => __('seo.search_console.step2'), 'code' => null],
                    ['text' => __('seo.search_console.step3'), 'code' => null],
                    ['text' => __('seo.search_console.step4'), 'code' => route('sitemap.index')],
                ],
            ],
            'tools' => [
                'heading' => __('admin.seo_page.tools_heading'),
                'description' => __('admin.seo_page.tools_description'),
                'links' => array_map(fn (string $key, string $url): array => ['key' => $key, 'label' => __('admin.seo_page.tools.'.$key), 'url' => $url], array_keys(SeoAudit::tools()), SeoAudit::tools()),
            ],
            'audit' => [
                'heading' => __('admin.seo_page.audit_heading'),
                'help' => __('seo.audit.help'),
                'empty' => __('admin.seo_page.empty'),
                'complete' => __('seo.audit.complete'),
                'tabs' => array_map(fn (string $tab): array => ['key' => $tab, 'label' => __('admin.seo_page.filters.'.$tab)], SeoAudit::FILTERS),
            ],
        ]]);
    }

    /** Una pagina del controllo: `tab` `issues` (predefinita) o `all`, `per_page` 20 come il pannello (al massimo 50). */
    public function audit(Request $request): JsonResponse
    {
        $this->authorizeSeo($request);
        $perPage = min(config()->integer('api.limits.max', 50), max(1, $request->integer('per_page', SeoAudit::PER_PAGE)));
        $overview = $this->audit->overview($this->listTab($request, SeoAudit::FILTERS, 'issues'), $this->listPage($request), $perPage);
        $rows = array_values(array_map(fn (array $row): array => [
            'key' => md5($row['url']),
            'title' => $row['title'],
            'url' => $row['url'],
            'issues' => $row['issues'],
            'complete' => $row['issues'] === [],
        ], $overview['rows']->items()));

        return response()->json($this->listPayload($overview['rows'], $rows, $overview['counts']));
    }

    /** Salva il codice di verifica (`city_id`, `verification`; vuoto lo toglie). */
    public function verification(Request $request): JsonResponse
    {
        $this->authorizeSeo($request);
        $city = $this->verification->validateAndSave($request->only(['city_id', 'verification']));

        return response()->json(['data' => $this->city($city->refresh()), 'meta' => ['message' => __('seo.search_console.saved')]]);
    }

    /** @return array{id: int, slug: string, name: string, is_active: bool, verification: ?string} */
    private function city(City $city): array
    {
        return [
            'id' => (int) $city->id,
            'slug' => (string) $city->slug,
            'name' => (string) $city->name,
            'is_active' => (bool) $city->is_active,
            'verification' => SearchConsoleVerification::code($city),
        ];
    }

    private function authorizeSeo(Request $request): void
    {
        EditorialDesk::require();
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(app(EditorialPermissions::class)->allows($user, 'analytics_view'), 403);
    }
}
