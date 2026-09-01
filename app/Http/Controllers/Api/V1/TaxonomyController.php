<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\InteractsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CategoryResource;
use App\Http\Resources\V1\TagResource;
use App\Models\Category;
use App\Models\Tag;
use App\Queries\EventOccurrenceQuery;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Categorie e tag (§7.4, §7.5) per un'applicazione che non legge dal sito.
 *
 * `/v1/config` ne porta già un estratto, ma l'estratto non basta a due
 * schermate reali: la griglia per categoria vuole il conteggio di quante date
 * ci sono davvero sotto ognuna, e la ricerca vuole i sinonimi.
 *
 * **I tre flag di §7.4 sono la ragione per cui questo endpoint esiste.**
 * `supports_ongoing`, `is_nightlife` e `default_duration_minutes` governano il
 * motore temporale: senza `supports_ongoing` un client non sa che una mostra
 * non compare mai in «in corso adesso», e finirebbe per calcolarselo da sé —
 * che è il modo in cui, in sei mesi, nascono due definizioni divergenti della
 * stessa cosa (§2.5).
 */
final class TaxonomyController extends Controller
{
    use InteractsWithApi;

    public function categories(): JsonResponse
    {
        $city = $this->city();

        /* Il conteggio è per città: la stessa categoria ha densità diverse a
           Padova e a Vicenza, e un client che disegna la griglia deve poter
           nascondere quelle vuote (§8.6). */
        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(static fn (Category $category): array => [
                ...CategoryResource::toArray($category),
                'upcoming_count' => EventOccurrenceQuery::for($city)
                    ->upcoming()
                    ->inCategories([$category])
                    ->count(),
            ])
            ->all();

        return ApiResponse::collection($categories);
    }

    public function category(string $slug): JsonResponse
    {
        $city = $this->city();

        $category = Category::query()
            ->where('is_active', true)
            ->where('slug', $slug)
            ->first();

        if (! $category instanceof Category) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        return ApiResponse::item([
            ...CategoryResource::toArray($category),
            'upcoming_count' => EventOccurrenceQuery::for($city)
                ->upcoming()
                ->inCategories([$category])
                ->count(),
        ]);
    }

    public function tags(): JsonResponse
    {
        $tags = Tag::query()
            ->where('is_approved', true)
            ->orderByDesc('usage_count')
            ->orderBy('name')
            ->get()
            ->map(static fn (Tag $tag): array => TagResource::toArray($tag))
            ->all();

        return ApiResponse::collection($tags);
    }

    public function tag(string $slug): JsonResponse
    {
        $city = $this->city();

        $tag = Tag::query()
            ->where('is_approved', true)
            ->where('slug', $slug)
            ->first();

        if (! $tag instanceof Tag) {
            throw new ApiException(ApiErrorCode::NotFound);
        }

        return ApiResponse::item([
            ...TagResource::toArray($tag),
            'upcoming_count' => EventOccurrenceQuery::for($city)
                ->upcoming()
                ->withTags([$tag->slug])
                ->count(),
        ]);
    }
}
