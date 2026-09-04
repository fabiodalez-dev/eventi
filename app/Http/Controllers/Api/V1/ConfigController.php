<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CategoryResource;
use App\Http\Resources\V1\CityResource;
use App\Http\Resources\V1\TagResource;
use App\Models\Category;
use App\Models\City;
use App\Models\Tag;
use App\Services\Search\FilterFacets;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * `GET /v1/config` (§13.1): la prima chiamata che un'app fa all'avvio.
 *
 * Porta ciò che serve a disegnare i filtri senza inventarli — categorie con i
 * tre campi che governano il tempo, tag popolari, città accese — più la
 * versione minima ammessa, gli interruttori delle funzioni e i testi legali.
 *
 * Gli interruttori dicono che cosa **esiste oggi** per chi li legge, cioè per
 * un'app nativa: `push` è spento perché per un'app push significa FCM, che
 * appartiene a F11 — il Web Push riaperto da D54 vive nel browser, dove
 * nessuna app lo legge. Un'app che li legge non mostra un pulsante che non
 * funziona, ed è tutta la ragione per cui questo endpoint esiste.
 */
final class ConfigController extends Controller
{
    public function __construct(private readonly FilterFacets $facets) {}

    public function __invoke(): JsonResponse
    {
        return ApiResponse::item([
            'api_version' => config()->string('api.version'),
            'min_app_version' => config()->array('api.min_app_version'),
            'features' => config()->array('api.features'),
            'limits' => [
                'default' => config()->integer('api.limits.default'),
                'max' => config()->integer('api.limits.max'),
                'map_max' => config()->integer('api.limits.map_max'),
            ],
            'cities' => City::query()
                ->active()
                ->orderBy('name')
                ->get()
                ->map(static fn (City $city): array => CityResource::toArray($city))
                ->all(),
            'categories' => $this->facets->categories()
                ->map(static fn (Category $category): array => CategoryResource::toArray($category))
                ->all(),
            'tags' => $this->facets->tags()
                ->map(static fn (Tag $tag): array => TagResource::toArray($tag))
                ->all(),
            'legal' => [
                'terms' => [
                    'title' => __('api.legal.terms'),
                    'url' => self::setting('api.legal.terms_url'),
                ],
                'privacy' => [
                    'title' => __('api.legal.privacy'),
                    'url' => self::setting('api.legal.privacy_url'),
                ],
                'updated_at' => self::setting('api.legal.updated_at'),
            ],
        ]);
    }

    /**
     * I testi legali non esistono ancora come pagine: finché non ci sono, il
     * campo è `null` e l'app sa di non avere un collegamento da mostrare.
     * `config()->string()` qui non si può usare — solleverebbe un'eccezione
     * proprio sul valore assente, che è il caso normale.
     */
    private static function setting(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
